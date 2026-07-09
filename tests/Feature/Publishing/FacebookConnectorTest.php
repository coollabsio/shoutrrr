<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\FacebookConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<PostMedia>  $media
 */
function fbContext(array $segments, array $media = [], array $targetOverrides = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge(['platform' => Platform::Facebook->value], $targetOverrides));
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Facebook->value,
        'remote_account_id' => 'page123',
    ]);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    );
}

test('facebook creates a text post and returns the pageid_postid', function () {
    Http::fake([
        'https://graph.facebook.com/*/page123/feed' => Http::response(['id' => 'page123_555']),
    ]);

    $result = app(FacebookConnector::class)->publish(fbContext(['hello world']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['page123_555']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/page123/feed')
        && $request['message'] === 'hello world');
});

test('facebook includes a link field when the text contains a url', function () {
    Http::fake([
        'https://graph.facebook.com/*/page123/feed' => Http::response(['id' => 'page123_556']),
    ]);

    app(FacebookConnector::class)->publish(fbContext(['check this out https://example.com/post']));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/page123/feed')
        && $request['link'] === 'https://example.com/post');
});

test('facebook publishes a single photo and returns the post_id', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/pic.jpg',
        'mime' => 'image/jpeg',
    ]);

    Http::fake([
        'https://graph.facebook.com/*/page123/photos' => Http::response(['id' => '999', 'post_id' => 'page123_777']),
    ]);

    $result = app(FacebookConnector::class)->publish(fbContext(['look'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['page123_777']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/page123/photos')) {
            return false;
        }

        $parts = collect($request->data());

        return $parts->contains(fn ($part) => ($part['name'] ?? null) === 'source' && ($part['contents'] ?? null) === 'jpg-bytes')
            && $parts->contains(fn ($part) => ($part['name'] ?? null) === 'caption' && ($part['contents'] ?? null) === 'look')
            && $parts->contains(fn ($part) => ($part['name'] ?? null) === 'published' && ($part['contents'] ?? null) === 'true');
    });
});

test('facebook uploads a carousel of photos then posts attached_media to the feed', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/a.jpg', 'a-bytes');
    Storage::disk('public')->put('media/b.jpg', 'b-bytes');

    $first = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/a.jpg', 'mime' => 'image/jpeg']);
    $second = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/b.jpg', 'mime' => 'image/jpeg']);

    Http::fake([
        'https://graph.facebook.com/*/page123/photos*' => Http::sequence()
            ->push(['id' => 'fbid-1'])
            ->push(['id' => 'fbid-2']),
        'https://graph.facebook.com/*/page123/feed' => Http::response(['id' => 'page123_888']),
    ]);

    $result = app(FacebookConnector::class)->publish(fbContext(['carousel'], [$first, $second]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['page123_888']);

    // Both unpublished uploads went to /photos with published=false&temporary=true.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/page123/photos')
        && str_contains($request->url(), 'published=false')
        && str_contains($request->url(), 'temporary=true'));

    Http::assertSentCount(3);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/page123/feed')) {
            return false;
        }

        return $request['message'] === 'carousel'
            && $request['attached_media[0]'] === json_encode(['media_fbid' => 'fbid-1'])
            && $request['attached_media[1]'] === json_encode(['media_fbid' => 'fbid-2']);
    });
});

test('facebook maps a 401 to AuthExpired', function () {
    Http::fake([
        'https://graph.facebook.com/*/page123/feed' => Http::response(['error' => ['message' => 'expired']], 401),
    ]);

    $result = app(FacebookConnector::class)->publish(fbContext(['hi']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::AuthExpired);
});

test('facebook resume guard returns success without making any http calls', function () {
    Http::fake();

    $result = app(FacebookConnector::class)->publish(fbContext(['hi'], [], [
        'remote_id' => 'page123_555',
        'remote_ids' => ['page123_555'],
    ]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['page123_555']);

    Http::assertNothingSent();
});
