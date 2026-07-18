<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Jobs\CaptureAccountMetrics;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Http;

test('post job writes latest totals and ok status for bluesky', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 7, 'repostCount' => 1, 'quoteCount' => 0, 'replyCount' => 2],
    ]])]);

    $account = ConnectedAccount::factory()->bluesky()->create();

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    $target->refresh();
    expect($target->likes)->toBe(7);
    expect($target->comments)->toBe(2);
    expect($target->metrics_status)->toBe(MetricsStatus::Ok);
    expect($target->metrics_captured_at)->not->toBeNull();
});

test('account job appends a snapshot row', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['followersCount' => 99, 'followsCount' => 5, 'postsCount' => 3])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:x']);

    CaptureAccountMetrics::dispatchSync($account);

    expect($account->metrics()->count())->toBe(1);
    expect($account->metrics()->first()->followers)->toBe(99);
    expect($account->refresh()->metrics_status)->toBe(MetricsStatus::Ok);
});

test('post job resolves and forwards the facebook page token to the graph api', function () {
    // Regression: the metrics job gated credential resolution to X only, so
    // Facebook/Instagram/Threads reached their Graph connectors with an empty
    // token. Prove Facebook now resolves its stored Page token end-to-end.
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response(['data' => []]),
        'graph.facebook.com/*' => Http::response([
            'id' => '123_456',
            'likes' => ['summary' => ['total_count' => 4]],
            'comments' => ['summary' => ['total_count' => 1]],
            'shares' => ['count' => 0],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Facebook,
        'token_expires_at' => null,
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'page-token',
    ]);

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Facebook,
        'remote_id' => '123_456',
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    expect($target->refresh()->metrics_status)->toBe(MetricsStatus::Ok)
        ->and($target->likes)->toBe(4);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'access_token=page-token'));
});

test('post job resolves and forwards the discord webhook url to refetch reactions', function () {
    // Regression: the metrics job gated credential resolution to a platform
    // whitelist that omitted Discord, so DiscordMetricsConnector::fetchPost
    // always received an empty credentials array and failed every run. Prove
    // Discord now resolves its stored webhook URL end-to-end.
    Http::fake([
        'discord.com/api/webhooks/1/tok/messages/999' => Http::response([
            'reactions' => [['count' => 3], ['count' => 2]],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Discord,
        'token_expires_at' => null,
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'https://discord.com/api/webhooks/1/tok',
    ]);

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Discord,
        'posted_at' => now(),
        'remote_id' => '999',
        'remote_ids' => ['999'],
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    expect($target->refresh()->metrics_status)->toBe(MetricsStatus::Ok)
        ->and($target->likes)->toBe(5);
});

test('jobs no-op when feature disabled', function () {
    config(['metrics.enabled' => false]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);

    CaptureAccountMetrics::dispatchSync($account);

    expect($account->metrics()->count())->toBe(0);
});

test('jobs no-op when the instance-settings override disables metrics, even though config is on', function () {
    Http::preventStrayRequests();
    config(['metrics.enabled' => true]);
    app(InstanceSettings::class)->update(['metrics_enabled' => false]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);

    CaptureAccountMetrics::dispatchSync($account);

    expect($account->metrics()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a read that matches the prior totals increments the unchanged streak', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 7, 'repostCount' => 1, 'quoteCount' => 0, 'replyCount' => 2],
    ]])]);

    $account = ConnectedAccount::factory()->bluesky()->create();

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
        'likes' => 7,
        'comments' => 2,
        'reposts' => 1,
        'impressions' => null,
        'metrics_captured_at' => now()->subHours(2),
        'metrics_unchanged_streak' => 2,
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    expect($target->fresh()->metrics_unchanged_streak)->toBe(3);
});

test('a read that differs from the prior totals resets the unchanged streak', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 7, 'repostCount' => 1, 'quoteCount' => 0, 'replyCount' => 2],
    ]])]);

    $account = ConnectedAccount::factory()->bluesky()->create();

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
        'likes' => 5,
        'comments' => 2,
        'reposts' => 1,
        'impressions' => null,
        'metrics_captured_at' => now()->subHours(2),
        'metrics_unchanged_streak' => 4,
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    expect($target->fresh()->metrics_unchanged_streak)->toBe(0);
});

test('the first-ever capture never counts as unchanged, even when totals happen to match zero defaults', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 0, 'repostCount' => 0, 'quoteCount' => 0, 'replyCount' => 0],
    ]])]);

    $account = ConnectedAccount::factory()->bluesky()->create();

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
    ]);

    expect($target->metrics_captured_at)->toBeNull();

    CapturePostTargetMetrics::dispatchSync($target);

    expect($target->fresh()->metrics_unchanged_streak)->toBe(0);
});

test('a failed fetch leaves the unchanged streak untouched', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['error' => 'InternalServerError'], 500)]);

    $account = ConnectedAccount::factory()->bluesky()->create();

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
        'metrics_captured_at' => now()->subHours(2),
        'metrics_unchanged_streak' => 4,
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    $fresh = $target->fresh();
    expect($fresh->metrics_status)->toBe(MetricsStatus::Failed)
        ->and($fresh->metrics_unchanged_streak)->toBe(4);
});

test('jobs no-op for disabled accounts', function () {
    Http::preventStrayRequests();

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky,
        'disabled_at' => now(),
    ]);
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://a/app.bsky.feed.post/disabled',
    ]);

    CaptureAccountMetrics::dispatchSync($account);
    CapturePostTargetMetrics::dispatchSync($target);

    expect($account->metrics()->count())->toBe(0)
        ->and($account->fresh()->metrics_captured_at)->toBeNull()
        ->and($target->fresh()->metrics_captured_at)->toBeNull();

    Http::assertNothingSent();
});
