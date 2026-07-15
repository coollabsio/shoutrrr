<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\Engagement\Connectors\XEngagementConnector;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function xBatchAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create(['platform' => Platform::X, 'handle' => '@owner']);
}

function xBatchConnector(): XEngagementConnector
{
    return new XEngagementConnector(app(Factory::class));
}

test('fetchRepliesForConversations batches conversation ids into one search call', function () {
    Http::fake([
        'api.twitter.com/2/tweets/search/recent*' => Http::response([
            'data' => [
                ['id' => '900', 'text' => 'a', 'author_id' => '1', 'created_at' => '2026-06-25T10:00:00.000Z', 'conversation_id' => '500'],
                ['id' => '901', 'text' => 'b', 'author_id' => '1', 'created_at' => '2026-06-25T10:01:00.000Z', 'conversation_id' => '501'],
            ],
            'includes' => ['users' => [['id' => '1', 'username' => 'fan', 'name' => 'Fan']]],
        ]),
    ]);

    $out = xBatchConnector()->fetchRepliesForConversations(xBatchAccount(), ['500', '501', '502'], ['access_token' => 't'], null);

    Http::assertSentCount(1);
    expect($out['500']->replies)->toHaveCount(1);
    expect($out['500']->replies[0]->remoteReplyId)->toBe('900');
    expect($out['500']->replies[0]->parentRemoteId)->toBe('500');
    expect($out['501']->replies)->toHaveCount(1);
    expect($out['502']->replies)->toHaveCount(0);
    expect($out['502']->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => str_contains(urldecode($req->url()), 'conversation_id:500 OR conversation_id:501 OR conversation_id:502')
        && str_contains(urldecode($req->url()), '-from:owner')
        && ! str_contains(urldecode($req->url()), '-from:@'));
});

test('a rate-limited batch shares the failure across every id in the chunk', function () {
    Http::fake(['api.twitter.com/2/tweets/search/recent*' => Http::response(['title' => 'Too Many Requests'], 429, ['Retry-After' => '77'])]);

    $out = xBatchConnector()->fetchRepliesForConversations(xBatchAccount(), ['500', '501'], ['access_token' => 't'], null);

    expect($out['500']->status)->toBe(EngagementStatus::RateLimited);
    expect($out['500']->retryAfterSeconds)->toBe(77);
    expect($out['501']->status)->toBe(EngagementStatus::RateLimited);
});

test('a rate-limited first chunk stops further requests and marks every id', function () {
    Http::fake(['api.twitter.com/2/tweets/search/recent*' => Http::response(['title' => 'Too Many Requests'], 429, ['Retry-After' => '30'])]);

    // Enough conversation ids to span more than one query-length chunk.
    $ids = array_map('strval', range(1, 40));
    $out = xBatchConnector()->fetchRepliesForConversations(xBatchAccount(), $ids, ['access_token' => 't'], null);

    Http::assertSentCount(1);
    expect($out['1']->status)->toBe(EngagementStatus::RateLimited);
    expect($out['40']->status)->toBe(EngagementStatus::RateLimited);
    expect($out['40']->retryAfterSeconds)->toBe(30);
});
