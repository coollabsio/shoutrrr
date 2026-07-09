<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\InstagramMetricsConnector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->connector = app(InstagramMetricsConnector::class);
});

test('fetchPost maps insights metrics to ok', function () {
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response([
            'data' => [
                ['name' => 'likes', 'period' => 'lifetime', 'values' => [['value' => 9]]],
                ['name' => 'comments', 'period' => 'lifetime', 'values' => [['value' => 3]]],
                ['name' => 'saved', 'period' => 'lifetime', 'values' => [['value' => 1]]],
                ['name' => 'shares', 'period' => 'lifetime', 'values' => [['value' => 2]]],
                ['name' => 'reach', 'period' => 'lifetime', 'values' => [['value' => 300]]],
                ['name' => 'views', 'period' => 'lifetime', 'values' => [['value' => 250]]],
            ],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => '17800000000000000']);

    $r = $this->connector->fetchPost($account, $target, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->likes)->toBe(9);
    expect($r->comments)->toBe(3);
    expect($r->reposts)->toBe(2);
    expect($r->impressions)->toBe(250);
});

test('429 on insights maps to rate limited', function () {
    Http::fake(['graph.facebook.com/*/insights*' => Http::response([], 429)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => '17800000000000000']);

    expect($this->connector->fetchPost($account, $target, ['access_token' => 't'])->status)
        ->toBe(MetricsStatus::RateLimited);
});

test('fetchAccount maps followers_count and media_count', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123', 'followers_count' => 42, 'media_count' => 7])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => '123']);

    $r = $this->connector->fetchAccount($account, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->followers)->toBe(42);
    expect($r->postsCount)->toBe(7);
});
