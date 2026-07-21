<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\DiscordMetricsConnector;
use Illuminate\Support\Facades\Http;

const METRICS_HOOK = 'https://discord.com/api/webhooks/1/tok';

beforeEach(function () {
    $this->connector = app(DiscordMetricsConnector::class);
});

test('fetchPost sums reaction counts across all segment messages into likes', function () {
    Http::fake([
        METRICS_HOOK.'/messages/m1' => Http::response(['reactions' => [
            ['count' => 3, 'emoji' => ['name' => '👍']],
            ['count' => 2, 'emoji' => ['name' => '🎉']],
        ]]),
        METRICS_HOOK.'/messages/m2' => Http::response(['reactions' => [
            ['count' => 4, 'emoji' => ['name' => '❤️']],
        ]]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Discord->value]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::Discord->value,
        'remote_ids' => ['m1', 'm2'],
    ]);

    $r = $this->connector->fetchPost($account, $target, ['webhook_url' => METRICS_HOOK]);

    expect($r->isOk())->toBeTrue()
        ->and($r->likes)->toBe(9)
        ->and($r->comments)->toBe(0)
        ->and($r->reposts)->toBe(0)
        ->and($r->impressions)->toBeNull();
});

test('fetchPost treats a message with no reactions field as zero likes', function () {
    Http::fake([METRICS_HOOK.'/messages/m1' => Http::response(['id' => 'm1'])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Discord->value]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::Discord->value,
        'remote_ids' => ['m1'],
    ]);

    $r = $this->connector->fetchPost($account, $target, ['webhook_url' => METRICS_HOOK]);

    expect($r->isOk())->toBeTrue()->and($r->likes)->toBe(0);
});

test('fetchPost maps 429 to rate limited', function () {
    Http::fake([METRICS_HOOK.'/messages/m1' => Http::response(['message' => 'slow'], 429)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Discord->value]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::Discord->value,
        'remote_ids' => ['m1'],
    ]);

    expect($this->connector->fetchPost($account, $target, ['webhook_url' => METRICS_HOOK])->status)
        ->toBe(MetricsStatus::RateLimited);
});

test('fetchAccount is unsupported for Discord webhooks', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Discord->value]);

    expect($this->connector->fetchAccount($account, ['webhook_url' => METRICS_HOOK])->status)
        ->toBe(MetricsStatus::Unsupported);
});
