<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\FacebookNativeReadConnector;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->connector = app(FacebookNativeReadConnector::class));

test('parses page feed', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['id' => 'p_1', 'message' => 'update', 'created_time' => '2026-09-02T10:00:00+0000', 'full_picture' => 'https://cdn/p.jpg', 'from' => ['id' => 'page9']],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page9']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts[0]->remoteId)->toBe('p_1')
        ->and($result->posts[0]->media[0]->url)->toBe('https://cdn/p.jpg');
});

test('skips posts not authored by the page (visitor/other-admin wall posts)', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['id' => 'p_own', 'message' => 'from the page', 'created_time' => '2026-09-02T10:00:00+0000', 'from' => ['id' => 'page9']],
        ['id' => 'p_visitor', 'message' => 'from a visitor', 'created_time' => '2026-09-02T11:00:00+0000', 'from' => ['id' => 'someone_else']],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page9']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts)->toHaveCount(1)
        ->and($result->posts[0]->remoteId)->toBe('p_own');
});
