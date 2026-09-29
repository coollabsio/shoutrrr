<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\XNativeReadConnector;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->connector = app(XNativeReadConnector::class));

test('parses user tweets timeline', function () {
    Http::fake(['api.twitter.com/2/users/*/tweets*' => Http::response(['data' => [
        ['id' => '100', 'text' => 'hello world', 'created_at' => '2026-09-02T10:00:00.000Z'],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '42']);
    $cursor = new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null);

    $result = $this->connector->fetchRecent($account, $cursor, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts)->toHaveCount(1)
        ->and($result->posts[0]->remoteId)->toBe('100')
        ->and($result->newestRemoteId)->toBe('100');
});

test('429 maps to rate limited', function () {
    Http::fake(['api.twitter.com/*' => Http::response([], 429)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '42']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::now()->toImmutable(), null), ['access_token' => 't']);
    expect($result->status)->toBe(MetricsStatus::RateLimited);
});
