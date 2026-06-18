<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Jobs\CaptureAccountMetrics;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\ConnectedAccount;
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

test('jobs no-op when feature disabled', function () {
    config(['metrics.enabled' => false]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);

    CaptureAccountMetrics::dispatchSync($account);

    expect($account->metrics()->count())->toBe(0);
});
