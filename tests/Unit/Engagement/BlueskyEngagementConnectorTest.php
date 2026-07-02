<?php

use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Atproto\DPoP;
use App\Services\Engagement\Connectors\BlueskyEngagementConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function blueskyAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:owner']);
}

test('fetchReplies flattens the thread and excludes the owner and root', function () {
    Http::fake([
        'public.api.bsky.app/*' => Http::response([
            'thread' => [
                'post' => ['uri' => 'at://root', 'cid' => 'cidroot', 'author' => ['did' => 'did:plc:owner']],
                'replies' => [
                    [
                        'post' => [
                            'uri' => 'at://reply1', 'cid' => 'cid1',
                            'author' => ['did' => 'did:plc:fan', 'handle' => 'fan.bsky.social', 'displayName' => 'Fan', 'avatar' => 'http://a/x.jpg'],
                            'record' => ['text' => 'nice post', 'createdAt' => '2026-06-25T10:00:00Z', 'reply' => ['parent' => ['uri' => 'at://root']]],
                        ],
                        'replies' => [],
                    ],
                    [
                        'post' => [
                            'uri' => 'at://ownerreply', 'cid' => 'cid2',
                            'author' => ['did' => 'did:plc:owner', 'handle' => 'me.bsky.social'],
                            'record' => ['text' => 'my own follow-up', 'createdAt' => '2026-06-25T10:05:00Z'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $account = blueskyAccount();
    $target = PostTarget::factory()->create(['remote_id' => 'at://root', 'remote_ids' => ['at://root']]);

    $result = (new BlueskyEngagementConnector(app(Factory::class), app(DPoP::class)))
        ->fetchReplies($account, $target, [], null);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(1);
    expect($result->replies[0]->remoteReplyId)->toBe('at://reply1');
    expect($result->replies[0]->authorHandle)->toBe('fan.bsky.social');
    expect($result->replies[0]->parentRemoteId)->toBe('at://root');
});

test('fetchReplies drops replies at or before since', function () {
    Http::fake([
        'public.api.bsky.app/*' => Http::response([
            'thread' => [
                'post' => ['uri' => 'at://root', 'author' => ['did' => 'did:plc:owner']],
                'replies' => [
                    ['post' => [
                        'uri' => 'at://old', 'cid' => 'c', 'author' => ['did' => 'did:plc:fan', 'handle' => 'fan'],
                        'record' => ['text' => 'old', 'createdAt' => '2026-06-25T09:00:00Z'],
                    ]],
                ],
            ],
        ]),
    ]);

    $result = (new BlueskyEngagementConnector(app(Factory::class), app(DPoP::class)))
        ->fetchReplies(blueskyAccount(), PostTarget::factory()->create(['remote_id' => 'at://root']), [], CarbonImmutable::parse('2026-06-25T09:30:00Z'));

    expect($result->replies)->toHaveCount(0);
});

test('postReply creates a record threaded under the parent', function () {
    Http::fake([
        '*com.atproto.repo.getRecord*' => Http::response(['value' => ['reply' => ['root' => ['uri' => 'at://root', 'cid' => 'cidroot']]]]),
        '*com.atproto.repo.createRecord*' => Http::response(['uri' => 'at://mine', 'cid' => 'cidmine']),
    ]);

    $parent = PostTargetReply::factory()->create(['remote_reply_id' => 'at://reply1', 'remote_cid' => 'cid1']);

    $result = (new BlueskyEngagementConnector(app(Factory::class), app(DPoP::class)))
        ->postReply(blueskyAccount(), $parent, 'thanks!', ['session' => ['pds' => 'https://bsky.social', 'accessJwt' => 'jwt']]);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('at://mine');
    expect($result->remoteCid)->toBe('cidmine');

    Http::assertSent(fn ($req) => str_contains($req->url(), 'createRecord')
        && $req['record']['reply']['parent']['uri'] === 'at://reply1'
        && $req['record']['reply']['root']['uri'] === 'at://root');
});

test('postReply falls back to the parent as root when the parent is the original post', function () {
    Http::fake([
        '*com.atproto.repo.getRecord*' => Http::response(['value' => []]),
        '*com.atproto.repo.createRecord*' => Http::response(['uri' => 'at://mine', 'cid' => 'cidmine']),
    ]);

    $parent = PostTargetReply::factory()->create(['remote_reply_id' => 'at://did:plc:author/app.bsky.feed.post/abc', 'remote_cid' => 'cid1']);

    $result = (new BlueskyEngagementConnector(app(Factory::class), app(DPoP::class)))
        ->postReply(blueskyAccount(), $parent, 'thanks!', ['session' => ['pds' => 'https://bsky.social', 'accessJwt' => 'jwt']]);

    expect($result->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => str_contains($req->url(), 'createRecord')
        && $req['record']['reply']['root']['uri'] === 'at://did:plc:author/app.bsky.feed.post/abc'
        && $req['record']['reply']['root']['cid'] === 'cid1');
});
