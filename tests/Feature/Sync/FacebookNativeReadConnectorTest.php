<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\FacebookNativeReadConnector;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->connector = app(FacebookNativeReadConnector::class));

test('parses page feed with a video and full-size album photos', function () {
    Http::fake([
        'graph.facebook.com/*/page9/feed*' => Http::response(['data' => [
            ['id' => 'p_1', 'message' => 'update', 'created_time' => '2026-09-02T10:00:00+0000', 'from' => ['id' => 'page9'], 'attachments' => ['data' => [
                ['media_type' => 'video', 'media' => ['image' => ['src' => 'https://cdn/thumb.jpg'], 'source' => 'https://cdn/v.mp4'], 'target' => ['id' => 'v1']],
            ]]],
            ['id' => 'p_2', 'message' => 'album', 'created_time' => '2026-09-02T09:00:00+0000', 'from' => ['id' => 'page9'], 'attachments' => ['data' => [
                ['media_type' => 'album', 'subattachments' => ['data' => [
                    ['media_type' => 'photo', 'media' => ['image' => ['src' => 'https://cdn/a-720.jpg']], 'target' => ['id' => 'ph_a']],
                    ['media_type' => 'photo', 'media' => ['image' => ['src' => 'https://cdn/b-720.jpg']], 'target' => ['id' => 'ph_b']],
                ]]],
            ]]],
        ]]),
        // Full-size lookup only knows ph_a; ph_b falls back to its attachment src.
        'graph.facebook.com/*' => Http::response(['ph_a' => ['images' => [
            ['width' => 720, 'source' => 'https://cdn/a-720.jpg'],
            ['width' => 2048, 'source' => 'https://cdn/a-2048.jpg'],
        ]]]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page9']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts[0]->remoteId)->toBe('p_1')
        ->and(array_map(fn ($m) => [$m->url, $m->kind], $result->posts[0]->media))->toBe([['https://cdn/v.mp4', 'video']])
        ->and(array_map(fn ($m) => [$m->url, $m->kind], $result->posts[1]->media))->toBe([
            ['https://cdn/a-2048.jpg', 'image'],
            ['https://cdn/b-720.jpg', 'image'],
        ]);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'ids=ph_a%2Cph_b'));
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
