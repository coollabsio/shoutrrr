<?php

use App\Enums\Platform;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;

test('an ok poll appends a snapshot row matching the latest totals', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 12, 'repostCount' => 3, 'quoteCount' => 0, 'replyCount' => 4],
    ]])]);

    $account = ConnectedAccount::factory()->bluesky()->create();
    $target = PostTarget::factory()->create([
        'platform' => Platform::Bluesky,
        'connected_account_id' => $account->id,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    expect($target->metrics()->count())->toBe(1);
    $snapshot = $target->metrics()->first();
    expect($snapshot->likes)->toBe(12);
    expect($snapshot->comments)->toBe(4);
    expect($snapshot->reposts)->toBe(3);
    // Snapshot capture time equals the target's latest-capture timestamp.
    expect($snapshot->captured_at->equalTo($target->refresh()->metrics_captured_at))->toBeTrue();
});

test('a failed poll writes no snapshot', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response([], 500)]);

    $account = ConnectedAccount::factory()->bluesky()->create();
    $target = PostTarget::factory()->create([
        'platform' => Platform::Bluesky,
        'connected_account_id' => $account->id,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    expect($target->metrics()->count())->toBe(0);
});
