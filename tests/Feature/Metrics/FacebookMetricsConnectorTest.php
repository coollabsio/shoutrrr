<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\FacebookMetricsConnector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->connector = app(FacebookMetricsConnector::class);
});

test('fetchPost maps counts and insights impressions to ok', function () {
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response([
            'data' => [['name' => 'post_impressions', 'period' => 'lifetime', 'values' => [['value' => 250]]]],
        ]),
        'graph.facebook.com/*' => Http::response([
            'id' => '123_456',
            'likes' => ['summary' => ['total_count' => 9]],
            'comments' => ['summary' => ['total_count' => 3]],
            'shares' => ['count' => 2],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook]);
    $target = PostTarget::factory()->create(['platform' => Platform::Facebook, 'remote_id' => '123_456']);

    $r = $this->connector->fetchPost($account, $target, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->likes)->toBe(9);
    expect($r->comments)->toBe(3);
    expect($r->reposts)->toBe(2);
    expect($r->impressions)->toBe(250);
});

test('fetchPost returns ok with null impressions when insights call fails', function () {
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response(['error' => ['message' => 'unsupported']], 400),
        'graph.facebook.com/*' => Http::response([
            'id' => '123_456',
            'likes' => ['summary' => ['total_count' => 1]],
            'comments' => ['summary' => ['total_count' => 0]],
            'shares' => ['count' => 0],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook]);
    $target = PostTarget::factory()->create(['platform' => Platform::Facebook, 'remote_id' => '123_456']);

    $r = $this->connector->fetchPost($account, $target, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->likes)->toBe(1);
    expect($r->impressions)->toBeNull();
});

test('429 on counts maps to rate limited', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([], 429)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook]);
    $target = PostTarget::factory()->create(['platform' => Platform::Facebook, 'remote_id' => '123_456']);

    expect($this->connector->fetchPost($account, $target, ['access_token' => 't'])->status)
        ->toBe(MetricsStatus::RateLimited);
});

test('fetchAccount maps followers_count', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123', 'followers_count' => 42])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => '123']);

    $r = $this->connector->fetchAccount($account, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->followers)->toBe(42);
});
