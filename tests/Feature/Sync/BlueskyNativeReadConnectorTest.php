<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\BlueskyNativeReadConnector;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->connector = app(BlueskyNativeReadConnector::class));

test('parses author feed, dropping replies and reposts', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => [
        ['post' => ['uri' => 'at://did/app.bsky.feed.post/1', 'record' => ['text' => 'original', 'createdAt' => '2026-09-02T10:00:00Z'], 'embed' => null]],
        ['post' => ['uri' => 'at://did/app.bsky.feed.post/2', 'record' => ['text' => 'a reply', 'createdAt' => '2026-09-02T10:05:00Z', 'reply' => ['parent' => []]]]],
        ['post' => ['uri' => 'at://did/app.bsky.feed.post/3', 'record' => ['text' => 'x', 'createdAt' => '2026-09-02T10:06:00Z']], 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost']],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:abc']);
    $cursor = new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null);

    $result = $this->connector->fetchRecent($account, $cursor, []);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts)->toHaveCount(1)
        ->and($result->posts[0]->remoteId)->toBe('at://did/app.bsky.feed.post/1')
        ->and($result->posts[0]->isReply)->toBeFalse();
});

test('reads images from image, gallery and quote-with-media embeds', function (array $embed) {
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => [
        ['post' => ['uri' => 'at://did/app.bsky.feed.post/1', 'record' => ['text' => 'pics', 'createdAt' => '2026-09-02T10:00:00Z'], 'embed' => $embed]],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:abc']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), []);

    expect(array_map(fn ($m) => $m->url, $result->posts[0]->media))->toBe(['https://cdn/1.jpg', 'https://cdn/2.jpg']);
})->with([
    'images' => [['$type' => 'app.bsky.embed.images#view', 'images' => [['fullsize' => 'https://cdn/1.jpg'], ['fullsize' => 'https://cdn/2.jpg']]]],
    'gallery' => [['$type' => 'app.bsky.embed.gallery#view', 'items' => [['fullsize' => 'https://cdn/1.jpg'], ['fullsize' => 'https://cdn/2.jpg']]]],
    'quote with media' => [['$type' => 'app.bsky.embed.recordWithMedia#view', 'record' => [], 'media' => ['images' => [['fullsize' => 'https://cdn/1.jpg'], ['fullsize' => 'https://cdn/2.jpg']]]]],
]);

test('reads a video embed as the original blob on the author PDS', function (array $embed) {
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['feed' => [
            ['post' => ['uri' => 'at://did/app.bsky.feed.post/1', 'record' => ['text' => 'clip', 'createdAt' => '2026-09-02T10:00:00Z'], 'embed' => $embed]],
        ]]),
        'plc.directory/*' => Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://resolved.host.bsky.network']]]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:abc']);
    $cursor = new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null);
    $blobUrl = fn (array $credentials) => $this->connector->fetchRecent($account, $cursor, $credentials)->posts[0]->media[0]->url;

    expect($this->connector->fetchRecent($account, $cursor, ['session' => ['pds' => 'https://pds.example.com/']])->posts[0]->media[0]->kind)->toBe('video')
        ->and($blobUrl(['session' => ['pds' => 'https://pds.example.com/']]))->toBe('https://pds.example.com/xrpc/com.atproto.sync.getBlob?did=did%3Aplc%3Aabc&cid=bafkvid')
        // The bsky.social entryway redirects getBlob, and an unknown PDS must be looked up.
        ->and($blobUrl(['session' => ['pds' => 'https://bsky.social']]))->toStartWith('https://resolved.host.bsky.network/xrpc/')
        ->and($blobUrl([]))->toStartWith('https://resolved.host.bsky.network/xrpc/');
})->with([
    'video' => [['$type' => 'app.bsky.embed.video#view', 'cid' => 'bafkvid', 'playlist' => 'https://video.bsky.app/p.m3u8']],
    'quote with video' => [['$type' => 'app.bsky.embed.recordWithMedia#view', 'record' => [], 'media' => ['$type' => 'app.bsky.embed.video#view', 'cid' => 'bafkvid', 'playlist' => 'https://video.bsky.app/p.m3u8']]],
]);

test('does not look up the PDS when the feed has no video', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => [
        ['post' => ['uri' => 'at://did/app.bsky.feed.post/1', 'record' => ['text' => 'text', 'createdAt' => '2026-09-02T10:00:00Z']]],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:abc']);
    $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), []);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'plc.directory'));
});
