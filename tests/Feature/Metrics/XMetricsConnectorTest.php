<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\XMetricsConnector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->connector = app(XMetricsConnector::class);
});

test('fetchPost parses public_metrics including impressions', function () {
    Http::fake(['api.twitter.com/2/tweets*' => Http::response(['data' => [[
        'id' => '1', 'public_metrics' => [
            'like_count' => 9, 'reply_count' => 3, 'retweet_count' => 2, 'quote_count' => 1, 'impression_count' => 500,
        ],
    ]]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $target = PostTarget::factory()->create(['platform' => Platform::X, 'remote_ids' => ['1']]);

    $r = $this->connector->fetchPost($account, $target, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->likes)->toBe(9);
    expect($r->reposts)->toBe(3);   // 2 + 1
    expect($r->comments)->toBe(3);
    expect($r->impressions)->toBe(500);
});

test('403 maps to unsupported', function () {
    Http::fake(['api.twitter.com/*' => Http::response([], 403)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $target = PostTarget::factory()->create(['platform' => Platform::X, 'remote_ids' => ['1']]);

    expect($this->connector->fetchPost($account, $target, ['access_token' => 't'])->status)
        ->toBe(MetricsStatus::Unsupported);
});

test('429 maps to rate limited', function () {
    Http::fake(['api.twitter.com/*' => Http::response([], 429)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $target = PostTarget::factory()->create(['platform' => Platform::X, 'remote_ids' => ['1']]);

    expect($this->connector->fetchPost($account, $target, ['access_token' => 't'])->status)
        ->toBe(MetricsStatus::RateLimited);
});
