<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\InstagramNativeReadConnector;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->connector = app(InstagramNativeReadConnector::class));

test('parses ig media', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['id' => 'm1', 'caption' => 'sunset', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/s.jpg', 'timestamp' => '2026-09-02T10:00:00+0000'],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'ig42']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts[0]->text)->toBe('sunset')
        ->and($result->posts[0]->media[0]->kind)->toBe('image');
});

test('reads every carousel child', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['id' => 'm1', 'caption' => 'album', 'media_type' => 'CAROUSEL_ALBUM', 'media_url' => 'https://cdn/1.jpg', 'timestamp' => '2026-09-02T10:00:00+0000', 'children' => ['data' => [
            ['id' => 'c1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/1.jpg'],
            ['id' => 'c2', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn/2.mp4'],
        ]]],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'ig42']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect(array_map(fn ($m) => [$m->url, $m->kind], $result->posts[0]->media))
        ->toBe([['https://cdn/1.jpg', 'image'], ['https://cdn/2.mp4', 'video']]);
});
