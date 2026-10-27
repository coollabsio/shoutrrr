<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\PostOrigin;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Services\NativeRead\ExternalIngestService;
use App\Support\FileStorage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    config(['sync.enabled' => true]);
});

function watchBluesky(string $workspaceId): ConnectedAccount
{
    // token_expires_at in the future keeps TokenManager::fresh() on its
    // no-refresh fast path — Bluesky's native-read connector hits the public
    // AppView API and needs no credentials at all, so no HTTP refresh call
    // should ever fire here.
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspaceId, 'platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:me', 'token_expires_at' => now()->addHour()]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id]);
    ConnectedAccountNativeWatch::create(['connected_account_id' => $account->id, 'workspace_id' => $workspaceId, 'enabled_at' => Date::parse('2026-09-01')]);

    return $account;
}

test('ingests a new native post as a read-only External post', function () {
    [, $workspace] = ownerActingIn();
    $account = watchBluesky($workspace->id);
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => [
        ['post' => ['uri' => 'at://did/1', 'record' => ['text' => 'native hello', 'createdAt' => '2026-09-02T10:00:00Z']]],
    ]])]);

    app(ExternalIngestService::class)->ingest($account);

    $post = Post::where('origin', PostOrigin::External->value)->first();
    expect($post)->not->toBeNull()
        ->and($post->base_text)->toBe('native hello')
        ->and($post->targets)->toHaveCount(1)
        ->and($post->targets->first()->status)->toBe(PostTargetStatus::Published)
        ->and($post->targets->first()->remote_id)->toBe('at://did/1');
    expect($account->nativeWatch->fresh()->last_seen_remote_id)->toBe('at://did/1');
});

test('does not re-ingest a native post we already have (anti-loop + dedup)', function () {
    [, $workspace] = ownerActingIn();
    $account = watchBluesky($workspace->id);
    // A prior post already carries this remote_id on a target for this account.
    $existing = Post::factory()->create(['workspace_id' => $workspace->id]);
    PostTarget::create(['post_id' => $existing->id, 'connected_account_id' => $account->id, 'platform' => Platform::Bluesky->value, 'sections' => ['x'], 'status' => PostTargetStatus::Published->value, 'remote_id' => 'at://did/1']);
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => [
        ['post' => ['uri' => 'at://did/1', 'record' => ['text' => 'dup', 'createdAt' => '2026-09-02T10:00:00Z']]],
    ]])]);

    app(ExternalIngestService::class)->ingest($account);

    expect(Post::where('origin', PostOrigin::External->value)->count())->toBe(0);
});

test('a failed image download does not wedge the account (post still created, cursor still advances)', function () {
    [, $workspace] = ownerActingIn();
    $account = watchBluesky($workspace->id);
    // Pipeline source so createExternalPost attempts the full media download.
    SyncPipeline::factory()->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $account->id,
        'enabled' => true,
    ]);
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['feed' => [
            ['post' => [
                'uri' => 'at://did/1',
                'record' => ['text' => 'native with image', 'createdAt' => '2026-09-02T10:00:00Z'],
                'embed' => ['images' => [
                    ['fullsize' => 'https://example.com/broken.jpg'],
                ]],
            ]],
        ]]),
        'https://example.com/*' => Http::response('', 404),
    ]);

    app(ExternalIngestService::class)->ingest($account);

    $post = Post::where('origin', PostOrigin::External->value)->first();
    expect($post)->not->toBeNull()
        ->and($post->targets)->toHaveCount(1)
        ->and($post->media)->toHaveCount(0)
        ->and($post->external_media[0]['url'])->toBe('https://example.com/broken.jpg');
    expect($account->nativeWatch->fresh()->last_seen_remote_id)->toBe('at://did/1');
});

test('a pipeline source downloads its video as a probed video media row', function () {
    [, $workspace] = ownerActingIn();
    Storage::fake(FileStorage::diskName());
    $account = watchBluesky($workspace->id);
    $account->secret->forceFill(['session' => ['pds' => 'https://example.com']])->save();
    SyncPipeline::factory()->create(['workspace_id' => $workspace->id, 'source_connected_account_id' => $account->id, 'enabled' => true]);
    $video = "\x00\x00\x00\x20".'ftypisom'.str_repeat("\x00", 64);
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['feed' => [
            ['post' => ['uri' => 'at://did/2', 'record' => ['text' => 'unprobeable', 'createdAt' => '2026-09-02T11:00:00Z'], 'embed' => ['cid' => 'bafkbad', 'playlist' => 'https://video.bsky.app/p.m3u8']]],
            ['post' => ['uri' => 'at://did/1', 'record' => ['text' => 'clip', 'createdAt' => '2026-09-02T10:00:00Z'], 'embed' => ['cid' => 'bafkvid', 'playlist' => 'https://video.bsky.app/p.m3u8']]],
        ]]),
        'https://example.com/*' => fn () => Http::response($video),
    ]);
    // Oldest-first ingest: the clip is probed first, then the unprobeable one.
    Process::fake(['*ffprobe*' => Process::sequence()
        ->push(Process::result(json_encode(['streams' => [['width' => 1280, 'height' => 720]], 'format' => ['duration' => '12.4']])))
        ->push(Process::result('', 'Invalid data', 1))]);

    app(ExternalIngestService::class)->ingest($account);

    $clip = Post::where('base_text', 'clip')->first();
    expect($clip->media)->toHaveCount(1)
        ->and($clip->media->first()->only(['kind', 'mime', 'width', 'height', 'duration_seconds', 'size_bytes']))
        ->toBe(['kind' => 'video', 'mime' => 'video/mp4', 'width' => 1280, 'height' => 720, 'duration_seconds' => 13, 'size_bytes' => strlen($video)]);
    Storage::disk(FileStorage::diskName())->assertExists($clip->media->first()->path);
    // No readable duration: skipped, but the post itself is still ingested.
    expect(Post::where('base_text', 'unprobeable')->first()->media)->toHaveCount(0);
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/xrpc/com.atproto.sync.getBlob?did=did%3Aplc%3Ame&cid=bafkvid');
});

test('skips media downloads once the run budget is spent but still ingests the post', function () {
    [, $workspace] = ownerActingIn();
    $account = watchBluesky($workspace->id);
    SyncPipeline::factory()->create(['workspace_id' => $workspace->id, 'source_connected_account_id' => $account->id, 'enabled' => true]);
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => [
        ['post' => ['uri' => 'at://did/1', 'record' => ['text' => 'late', 'createdAt' => '2026-09-02T10:00:00Z'], 'embed' => ['images' => [['fullsize' => 'https://example.com/a.jpg']]]]],
    ]])]);
    // The poll itself is instant; the clock then jumps past the budget before downloads.
    $this->travelTo(now());
    Http::globalRequestMiddleware(function ($request) {
        $this->travel(10)->minutes();

        return $request;
    });

    app(ExternalIngestService::class)->ingest($account);

    expect(Post::where('base_text', 'late')->first()->media)->toHaveCount(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'example.com'));
});

test('is inert when sync.enabled is false', function () {
    config(['sync.enabled' => false]);
    [, $workspace] = ownerActingIn();
    $account = watchBluesky($workspace->id);
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => []])]);

    app(ExternalIngestService::class)->ingest($account);

    expect(Post::where('origin', PostOrigin::External->value)->count())->toBe(0);
});
