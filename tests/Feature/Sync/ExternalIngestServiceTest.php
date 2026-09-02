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
use App\Services\NativeRead\ExternalIngestService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

test('is inert when sync.enabled is false', function () {
    config(['sync.enabled' => false]);
    [, $workspace] = ownerActingIn();
    $account = watchBluesky($workspace->id);
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => []])]);

    app(ExternalIngestService::class)->ingest($account);

    expect(Post::where('origin', PostOrigin::External->value)->count())->toBe(0);
});
