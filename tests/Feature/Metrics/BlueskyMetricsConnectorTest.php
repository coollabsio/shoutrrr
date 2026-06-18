<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\BlueskyMetricsConnector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->connector = app(BlueskyMetricsConnector::class);
});

test('fetchPost sums engagement across thread segments and discounts continuations', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 10, 'repostCount' => 2, 'quoteCount' => 1, 'replyCount' => 5],
        ['likeCount' => 4, 'repostCount' => 1, 'quoteCount' => 0, 'replyCount' => 2],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::Bluesky,
        'remote_ids' => ['at://a/app.bsky.feed.post/1', 'at://a/app.bsky.feed.post/2'],
    ]);

    $r = $this->connector->fetchPost($account, $target, []);

    expect($r->isOk())->toBeTrue();
    expect($r->likes)->toBe(14);
    expect($r->reposts)->toBe(4);   // (2+1)+(1+0)
    expect($r->comments)->toBe(6);  // (5+2) - (2-1)
    expect($r->impressions)->toBeNull();
});

test('fetchAccount reads follower counts from getProfile', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response([
        'followersCount' => 321, 'followsCount' => 100, 'postsCount' => 50,
    ])]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:abc',
    ]);

    $r = $this->connector->fetchAccount($account, []);

    expect($r->isOk())->toBeTrue();
    expect($r->followers)->toBe(321);
    expect($r->following)->toBe(100);
});
