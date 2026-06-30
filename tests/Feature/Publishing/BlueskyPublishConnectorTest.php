<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Atproto\DPoP;
use App\Services\Media\CompressionResult;
use App\Services\Media\ImageCompressor;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<string>  $segments
 * @param  list<PostMedia>  $media
 */
function bskyContext(array $segments, array $media = []): PublishContext
{
    $target = PostTarget::factory()->create(['platform' => Platform::Bluesky->value]);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
    );
}

test('bluesky creates a single post and returns its uri', function () {
    Http::fake([
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/1', 'cid' => 'cid1']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish(bskyContext(['hi there']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['at://did:plc:me/app.bsky.feed.post/1']);
});

test('bluesky oauth publish uses dpop authorization', function () {
    Http::fake([
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/1', 'cid' => 'cid1']),
    ]);

    $context = bskyContext(['oauth post']);
    $context = new PublishContext(
        target: $context->target,
        segments: $context->segments,
        media: $context->media,
        account: $context->account,
        credentials: ['session' => [
            'accessJwt' => 'oauth-token',
            'pds' => 'https://bsky.social',
            'dpop_private_jwk' => app(DPoP::class)->generateKey(),
            'dpop_nonce' => 'nonce-1',
        ]],
    );

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'com.atproto.repo.createRecord')
        && $request->hasHeader('Authorization', 'DPoP oauth-token')
        && $request->hasHeader('DPoP'));
});

test('bluesky resolves handles and sends mention facets', function () {
    Http::fake([
        '*com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:ada']),
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/1', 'cid' => 'cid1']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish(bskyContext(['Hi 👋 @ada.bsky.social']));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'com.atproto.repo.createRecord')) {
            return false;
        }

        return ($request['record']['facets'][0] ?? null) === [
            'index' => ['byteStart' => 8, 'byteEnd' => 24],
            'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:ada']],
        ];
    });
});

test('bluesky sends link facets for bare domains', function () {
    Http::fake([
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/1', 'cid' => 'cid1']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish(bskyContext(['Read shoutrrr.com now']));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'com.atproto.repo.createRecord')) {
            return false;
        }

        return ($request['record']['facets'][0] ?? null) === [
            'index' => ['byteStart' => 5, 'byteEnd' => 17],
            'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://shoutrrr.com']],
        ];
    });
});

test('bluesky excludes trailing punctuation from link facets', function () {
    Http::fake([
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/1', 'cid' => 'cid1']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish(bskyContext(['Read shoutrrr.com.']));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'com.atproto.repo.createRecord')) {
            return false;
        }

        return ($request['record']['facets'][0] ?? null) === [
            'index' => ['byteStart' => 5, 'byteEnd' => 17],
            'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://shoutrrr.com']],
        ];
    });
});

test('bluesky uploads media blobs and embeds them on the post', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
        'alt_text' => 'a cat',
    ]);

    Http::fake([
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://r/1', 'cid' => 'cid1']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish(bskyContext(['look'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['at://r/1']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'com.atproto.repo.uploadBlob')
        && $request->body() === 'image-bytes');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'com.atproto.repo.createRecord')) {
            return false;
        }

        $images = $request['record']['embed']['images'] ?? null;

        return is_array($images)
            && ($request['record']['embed']['$type'] ?? null) === 'app.bsky.embed.images'
            && ($images[0]['alt'] ?? null) === 'a cat'
            && ($images[0]['image']['ref']['$link'] ?? null) === 'bafblob';
    });
});

test('bluesky threads with reply root and parent refs', function () {
    Http::fake([
        '*com.atproto.repo.createRecord' => Http::sequence()
            ->push(['uri' => 'at://r/1', 'cid' => 'cidroot'])
            ->push(['uri' => 'at://r/2', 'cid' => 'cid2']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish(bskyContext(['root', 'reply']));

    expect($result->remoteIds)->toBe(['at://r/1', 'at://r/2']);

    Http::assertSent(function ($request) {
        $record = $request['record'] ?? null;

        return is_array($record)
            && isset($record['reply']['root']['uri'])
            && $record['reply']['root']['uri'] === 'at://r/1'
            && $record['reply']['parent']['uri'] === 'at://r/1';
    });
});

test('bluesky resume recovers cids and threads the resumed segment', function () {
    $target = PostTarget::factory()->create([
        'platform' => Platform::Bluesky->value,
        'remote_ids' => ['at://did:plc:me/app.bsky.feed.post/root'],
    ]);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);

    Http::fake([
        '*com.atproto.repo.getRecord*' => Http::response([
            'uri' => 'at://did:plc:me/app.bsky.feed.post/root',
            'cid' => 'rootcid',
        ]),
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/2', 'cid' => 'cid2']),
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['root segment', 'resumed reply'],
        media: [],
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
    );

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe([
            'at://did:plc:me/app.bsky.feed.post/root',
            'at://did:plc:me/app.bsky.feed.post/2',
        ]);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'com.atproto.repo.createRecord')) {
            return false;
        }

        $reply = $request['record']['reply'] ?? null;

        return is_array($reply)
            && ($reply['root']['uri'] ?? null) === 'at://did:plc:me/app.bsky.feed.post/root'
            && ($reply['root']['cid'] ?? null) === 'rootcid'
            && ($reply['parent']['uri'] ?? null) === 'at://did:plc:me/app.bsky.feed.post/root'
            && ($reply['parent']['cid'] ?? null) === 'rootcid';
    });
});

test('bluesky persists each segment uri before sending the next (mid-thread death is safe)', function () {
    Http::fake([
        '*com.atproto.repo.createRecord' => Http::sequence()
            ->push(['uri' => 'at://r/1', 'cid' => 'cidroot'])
            ->push([], 500),
    ]);

    $context = bskyContext(['root', 'reply']);

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse();

    // Segment 0 must be persisted before segment 1 was attempted.
    expect($context->target->fresh()->remote_ids)->toBe(['at://r/1']);
    expect($context->target->fresh()->remote_id)->toBe('at://r/1');
});

test('bluesky maps 401 to AuthExpired', function () {
    Http::fake(['*com.atproto.repo.createRecord' => Http::response(['error' => 'ExpiredToken'], 401)]);

    expect(app(BlueskyPublishConnector::class)->publish(bskyContext(['hi']))->errorKind)
        ->toBe(ErrorKind::AuthExpired);
});

test('bluesky compresses oversized images via the compressor before upload', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/big.jpg', 'image-bytes');

    $compressor = Mockery::mock(ImageCompressor::class);
    $compressor->shouldReceive('compressToFit')
        ->once()
        ->with('image-bytes', Platform::Bluesky->maxMediaBytes(), 'image/jpeg', Platform::Bluesky->allowedMime())
        ->andReturn(CompressionResult::compressed('small-bytes', 'image/webp'));
    app()->instance(ImageCompressor::class, $compressor);

    $media = PostMedia::factory()->create([
        'disk' => 'public', 'path' => 'media/big.jpg', 'mime' => 'image/jpeg', 'alt_text' => 'big',
    ]);

    Http::fake([
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://r/1', 'cid' => 'cid1']),
    ]);

    app(BlueskyPublishConnector::class)->publish(bskyContext(['look'], [$media]));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'com.atproto.repo.uploadBlob')
        && $request->body() === 'small-bytes'
        && $request->hasHeader('Content-Type', 'image/webp'));
});
