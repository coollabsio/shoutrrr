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

test('maps attached photos from the media expansion, skipping video', function () {
    Http::fake(['api.twitter.com/2/users/*/tweets*' => Http::response([
        'data' => [
            ['id' => '100', 'text' => 'pics', 'created_at' => '2026-09-02T10:00:00.000Z', 'attachments' => ['media_keys' => ['3_b', '7_v', '3_a']]],
        ],
        'includes' => ['media' => [
            ['media_key' => '3_a', 'type' => 'photo', 'url' => 'https://pbs.twimg.com/a.jpg'],
            ['media_key' => '3_b', 'type' => 'photo', 'url' => 'https://pbs.twimg.com/b.jpg'],
            ['media_key' => '7_v', 'type' => 'video'],
        ]],
    ])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '42']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect(array_map(fn ($m) => $m->url, $result->posts[0]->media))
        ->toBe(['https://pbs.twimg.com/b.jpg', 'https://pbs.twimg.com/a.jpg']);
});

test('strips media t.co links, expands other links and decodes entities', function () {
    Http::fake(['api.twitter.com/2/users/*/tweets*' => Http::response(['data' => [
        [
            'id' => '100',
            'text' => 'Tips &amp; tricks https://t.co/link https://t.co/pic1 https://t.co/pic2',
            'created_at' => '2026-09-02T10:00:00.000Z',
            'entities' => ['urls' => [
                ['url' => 'https://t.co/link', 'expanded_url' => 'https://example.com/post'],
                ['url' => 'https://t.co/pic1', 'expanded_url' => 'https://x.com/u/status/100/photo/1', 'media_key' => '3_a'],
                ['url' => 'https://t.co/pic2', 'expanded_url' => 'https://x.com/u/status/100/video/1'],
            ]],
        ],
        [
            'id' => '101',
            'text' => 'Truncated… https://t.co/more',
            'created_at' => '2026-09-02T11:00:00.000Z',
            'note_tweet' => ['text' => 'The full long post https://t.co/x', 'entities' => ['urls' => [
                ['url' => 'https://t.co/x', 'expanded_url' => 'https://example.com/full'],
            ]]],
        ],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '42']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect($result->posts[0]->text)->toBe('Tips & tricks https://example.com/post')
        ->and($result->posts[1]->text)->toBe('The full long post https://example.com/full');
});
