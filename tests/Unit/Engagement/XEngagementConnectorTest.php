<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\XEngagementConnector;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function xAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '111', 'handle' => '@owner']);
}

function xConnector(): XEngagementConnector
{
    return new XEngagementConnector(app(Factory::class));
}

test('fetchReplies parses the conversation search and resolves authors', function () {
    Http::fake([
        'api.twitter.com/2/tweets/search/recent*' => Http::response([
            'data' => [
                ['id' => '900', 'text' => 'great', 'author_id' => '222', 'created_at' => '2026-06-25T10:00:00.000Z', 'in_reply_to_user_id' => '111'],
            ],
            'includes' => ['users' => [
                ['id' => '222', 'username' => 'fan', 'name' => 'Fan', 'profile_image_url' => 'http://a/p.jpg'],
            ]],
        ]),
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::X, 'remote_id' => '500', 'remote_ids' => ['500']]);

    $result = xConnector()->fetchReplies(xAccount(), $target, ['access_token' => 't'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(1);
    expect($result->replies[0]->remoteReplyId)->toBe('900');
    expect($result->replies[0]->authorHandle)->toBe('fan');
    expect($result->replies[0]->authorAvatarUrl)->toBe('http://a/p.jpg');

    // The '@' on the stored handle must be stripped before it reaches the
    // search `from:` operator, otherwise X rejects the query as invalid.
    Http::assertSent(fn ($req) => str_contains($req->url(), '/2/tweets/search/recent')
        && str_contains(urldecode($req->url()), 'conversation_id:500 -from:owner')
        && ! str_contains(urldecode($req->url()), '-from:@'));
});

test('fetchReplies maps 403 to unsupported (no paid tier)', function () {
    Http::fake(['api.twitter.com/2/tweets/search/recent*' => Http::response(['title' => 'Forbidden'], 403)]);

    $result = xConnector()->fetchReplies(xAccount(), PostTarget::factory()->create(['platform' => Platform::X, 'remote_id' => '500']), ['access_token' => 't'], null);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('postReply posts an in_reply_to tweet', function () {
    Http::fake(['api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '999']])]);

    $parent = PostTargetReply::factory()->create(['platform' => Platform::X, 'remote_reply_id' => '900', 'remote_cid' => null]);

    $result = xConnector()->postReply(xAccount(), $parent, 'thanks', ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('999');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/2/tweets')
        && $req['reply']['in_reply_to_tweet_id'] === '900');
});
