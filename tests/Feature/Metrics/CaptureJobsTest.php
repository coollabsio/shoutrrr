<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Jobs\CaptureAccountMetrics;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\PostTarget;
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

test('jobs no-op when feature disabled', function () {
    config(['metrics.enabled' => false]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);

    CaptureAccountMetrics::dispatchSync($account);

    expect($account->metrics()->count())->toBe(0);
});
