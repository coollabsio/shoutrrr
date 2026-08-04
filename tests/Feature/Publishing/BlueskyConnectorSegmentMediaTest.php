<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('media attaches to the section the resolver assigned, not always the first', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
        'alt_text' => 'a cat',
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::Bluesky->value]);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);

    $context = new PublishContext(
        target: $target,
        segments: ['first (no media)', 'second (has media)'],
        media: [$media],
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
        mediaBySection: [1 => [$media]],
    );

    Http::fake([
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::sequence()
            ->push(['uri' => 'at://r/1', 'cid' => 'cid1'])
            ->push(['uri' => 'at://r/2', 'cid' => 'cid2']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['at://r/1', 'at://r/2']);

    $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'com.atproto.repo.createRecord'))
        ->values();

    expect($requests)->toHaveCount(2);

    $firstRecord = $requests[0][0]['record'];
    $secondRecord = $requests[1][0]['record'];

    expect($firstRecord['text'])->toBe('first (no media)')
        ->and($firstRecord)->not->toHaveKey('embed');

    expect($secondRecord['text'])->toBe('second (has media)')
        ->and($secondRecord['embed']['$type'] ?? null)->toBe('app.bsky.embed.images')
        ->and($secondRecord['embed']['images'][0]['alt'] ?? null)->toBe('a cat')
        ->and($secondRecord['embed']['images'][0]['image']['ref']['$link'] ?? null)->toBe('bafblob');
});

test('a resumed publish still uploads and embeds images for a not-yet-posted section', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
        'alt_text' => 'a cat',
    ]);

    $target = PostTarget::factory()->create([
        'platform' => Platform::Bluesky->value,
        'remote_ids' => ['at://did:plc:me/app.bsky.feed.post/root'],
    ]);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);

    $context = new PublishContext(
        target: $target,
        segments: ['root segment', 'second (has media)'],
        media: [$media],
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
        mediaBySection: [1 => [$media]],
    );

    Http::fake([
        '*com.atproto.repo.getRecord*' => Http::response([
            'uri' => 'at://did:plc:me/app.bsky.feed.post/root',
            'cid' => 'rootcid',
        ]),
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/2', 'cid' => 'cid2']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'com.atproto.repo.uploadBlob'));

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'com.atproto.repo.createRecord')) {
            return false;
        }

        $record = $request['record'] ?? [];

        return ($record['text'] ?? null) === 'second (has media)'
            && ($record['embed']['$type'] ?? null) === 'app.bsky.embed.images'
            && ($record['embed']['images'][0]['image']['ref']['$link'] ?? null) === 'bafblob';
    });
});

test('bluesky caps images per section, not per whole thread', function (): void {
    Storage::fake('public');

    $media = PostMedia::factory()->count(6)->sequence(fn ($seq) => [
        'disk' => 'public',
        'path' => "media/img{$seq->index}.jpg",
        'mime' => 'image/jpeg',
    ])->create();

    foreach ($media as $index => $item) {
        Storage::disk('public')->put("media/img{$index}.jpg", 'image-bytes');
    }

    $target = PostTarget::factory()->create(['platform' => Platform::Bluesky->value]);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);

    $firstThree = $media->take(3)->values()->all();
    $lastThree = $media->slice(3)->values()->all();

    $context = new PublishContext(
        target: $target,
        segments: ['first section', 'second section'],
        media: $media->all(),
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
        mediaBySection: [0 => $firstThree, 1 => $lastThree],
    );

    Http::fake([
        '*com.atproto.repo.uploadBlob' => Http::sequence()
            ->pushResponse(Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'baf0'], 'mimeType' => 'image/jpeg', 'size' => 11]]))
            ->pushResponse(Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'baf1'], 'mimeType' => 'image/jpeg', 'size' => 11]]))
            ->pushResponse(Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'baf2'], 'mimeType' => 'image/jpeg', 'size' => 11]]))
            ->pushResponse(Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'baf3'], 'mimeType' => 'image/jpeg', 'size' => 11]]))
            ->pushResponse(Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'baf4'], 'mimeType' => 'image/jpeg', 'size' => 11]]))
            ->pushResponse(Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'baf5'], 'mimeType' => 'image/jpeg', 'size' => 11]])),
        '*com.atproto.repo.createRecord' => Http::sequence()
            ->push(['uri' => 'at://r/1', 'cid' => 'cid1'])
            ->push(['uri' => 'at://r/2', 'cid' => 'cid2']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue();

    $requests = Http::recorded(fn ($request): bool => str_contains($request->url(), 'com.atproto.repo.createRecord'))
        ->values();

    $firstImages = $requests[0][0]['record']['embed']['images'] ?? [];
    $secondImages = $requests[1][0]['record']['embed']['images'] ?? [];

    expect($firstImages)->toHaveCount(3)
        ->and($secondImages)->toHaveCount(3);
});

test('a video on one section does not block image blobs for a later section', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/clip.mp4', str_repeat('x', 2048));
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $video = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/clip.mp4']);
    $image = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
        'alt_text' => 'a cat',
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::Bluesky->value]);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);

    $context = new PublishContext(
        target: $target,
        segments: ['first (has video)', 'second (has images)'],
        media: [$video, $image],
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
        mediaBySection: [0 => [$video], 1 => [$image]],
    );

    Http::fake([
        '*/xrpc/com.atproto.server.getServiceAuth*' => Http::response(['token' => 'svc'], 200),
        'video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['jobId' => 'job-1'], 200),
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response([
            'jobStatus' => ['state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'vidcid']]],
        ], 200),
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::sequence()
            ->push(['uri' => 'at://r/1', 'cid' => 'cid1'])
            ->push(['uri' => 'at://r/2', 'cid' => 'cid2']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['at://r/1', 'at://r/2']);

    $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'com.atproto.repo.createRecord'))
        ->values();

    expect($requests)->toHaveCount(2);

    $firstRecord = $requests[0][0]['record'];
    $secondRecord = $requests[1][0]['record'];

    expect($firstRecord['text'])->toBe('first (has video)')
        ->and($firstRecord['embed']['$type'] ?? null)->toBe('app.bsky.embed.video');

    expect($secondRecord['text'])->toBe('second (has images)')
        ->and($secondRecord['embed']['$type'] ?? null)->toBe('app.bsky.embed.images')
        ->and($secondRecord['embed']['images'][0]['alt'] ?? null)->toBe('a cat')
        ->and($secondRecord['embed']['images'][0]['image']['ref']['$link'] ?? null)->toBe('bafblob');
});

test('a resumed publish still embeds images for a pending section after the video section already posted', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/clip.mp4', str_repeat('x', 2048));
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $video = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/clip.mp4']);
    $image = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
        'alt_text' => 'a cat',
    ]);

    // The video-bearing root section already posted on a prior attempt; the image
    // section is still pending.
    $target = PostTarget::factory()->create([
        'platform' => Platform::Bluesky->value,
        'remote_ids' => ['at://did:plc:me/app.bsky.feed.post/root'],
    ]);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);

    $context = new PublishContext(
        target: $target,
        segments: ['first (has video)', 'second (has images)'],
        media: [$video, $image],
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
        mediaBySection: [0 => [$video], 1 => [$image]],
    );

    Http::fake([
        '*com.atproto.repo.getRecord*' => Http::response([
            'uri' => 'at://did:plc:me/app.bsky.feed.post/root',
            'cid' => 'rootcid',
        ]),
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/2', 'cid' => 'cid2']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue();

    // The video job status should never be polled — the video's section already posted.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'video.bsky.app'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'com.atproto.repo.uploadBlob'));

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'com.atproto.repo.createRecord')) {
            return false;
        }

        $record = $request['record'] ?? [];

        return ($record['text'] ?? null) === 'second (has images)'
            && ($record['embed']['$type'] ?? null) === 'app.bsky.embed.images'
            && ($record['embed']['images'][0]['image']['ref']['$link'] ?? null) === 'bafblob';
    });
});
