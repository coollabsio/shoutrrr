<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\ThreadsNativeReadConnector;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->connector = app(ThreadsNativeReadConnector::class));

test('parses threads media list with an image', function () {
    Http::fake(['graph.threads.net/*' => Http::response(['data' => [
        ['id' => 't1', 'text' => 'hi', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/x.jpg', 'timestamp' => '2026-09-02T10:00:00+0000'],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads, 'remote_account_id' => '9']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts)->toHaveCount(1)
        ->and($result->posts[0]->media[0]->kind)->toBe('image');
});
