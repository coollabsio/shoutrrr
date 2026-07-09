<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\FacebookEngagementConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function facebookConnector(): FacebookEngagementConnector
{
    return new FacebookEngagementConnector(app(Factory::class));
}

function facebookAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'platform' => Platform::Facebook,
        'remote_account_id' => 'PAGE1',
    ]);
}

test('fetchReplies maps comments to FetchedReply', function () {
    Http::fake([
        'graph.facebook.com/*/POST1/comments*' => Http::response([
            'data' => [
                [
                    'id' => 'C1',
                    'message' => 'nice post',
                    'from' => ['id' => 'U1', 'name' => 'Fan One'],
                    'created_time' => '2026-07-01T12:00:00+0000',
                    'like_count' => 2,
                ],
            ],
        ]),
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::Facebook, 'remote_id' => 'POST1']);

    $result = facebookConnector()->fetchReplies(facebookAccount(), $target, ['access_token' => 't'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(1);
    expect($result->replies[0]->remoteReplyId)->toBe('C1');
    expect($result->replies[0]->remoteCid)->toBeNull();
    expect($result->replies[0]->parentRemoteId)->toBe('POST1');
    expect($result->replies[0]->authorHandle)->toBe('Fan One');
    expect($result->replies[0]->authorName)->toBe('Fan One');
    expect($result->replies[0]->authorAvatarUrl)->toBeNull();
    expect($result->replies[0]->text)->toBe('nice post');
    expect($result->replies[0]->remoteCreatedAt)->toBeInstanceOf(CarbonImmutable::class);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/POST1/comments')
        && $req['filter'] === 'toplevel'
        && $req['order'] === 'chronological');
});

test('fetchReplies sends since when provided', function () {
    Http::fake(['graph.facebook.com/*/POST1/comments*' => Http::response(['data' => []])]);

    $target = PostTarget::factory()->create(['platform' => Platform::Facebook, 'remote_id' => 'POST1']);
    $since = CarbonImmutable::createFromTimestamp(1_700_000_000);

    facebookConnector()->fetchReplies(facebookAccount(), $target, ['access_token' => 't'], $since);

    Http::assertSent(fn ($req) => ($req['since'] ?? null) === $since->getTimestamp());
});

test('fetchReplies maps 403 to unsupported', function () {
    Http::fake(['graph.facebook.com/*/POST1/comments*' => Http::response(['error' => ['message' => 'no perms']], 403)]);

    $target = PostTarget::factory()->create(['platform' => Platform::Facebook, 'remote_id' => 'POST1']);

    $result = facebookConnector()->fetchReplies(facebookAccount(), $target, ['access_token' => 't'], null);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('postReply posts a comment and returns the id', function () {
    Http::fake(['graph.facebook.com/*/C1/comments' => Http::response(['id' => 'C2'])]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Facebook,
        'remote_reply_id' => 'C1',
        'parent_remote_id' => 'POST1',
    ]);

    $result = facebookConnector()->postReply(facebookAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('C2');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/C1/comments')
        && $req['message'] === 'thanks!'
        && $req['access_token'] === 't');
});

test('postReply declines media (Facebook comments cannot carry attachments)', function () {
    Http::preventStrayRequests();

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Facebook,
        'remote_reply_id' => 'C1',
        'parent_remote_id' => 'POST1',
    ]);

    $result = facebookConnector()->postReply(
        facebookAccount(),
        $parent,
        'with pic',
        ['access_token' => 't'],
        [PostMedia::factory()->make()],
    );

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('likeReply likes a comment', function () {
    Http::fake(['graph.facebook.com/*/C1/likes' => Http::response(['success' => true])]);

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Facebook,
        'remote_reply_id' => 'C1',
    ]);

    $result = facebookConnector()->likeReply(facebookAccount(), $reply, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => $req->method() === 'POST' && str_contains($req->url(), '/C1/likes'));
});

test('unlikeReply removes the like', function () {
    Http::fake(['graph.facebook.com/*/C1/likes*' => Http::response(['success' => true])]);

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Facebook,
        'remote_reply_id' => 'C1',
    ]);

    $result = facebookConnector()->unlikeReply(facebookAccount(), $reply, null, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_contains($req->url(), '/C1/likes'));
});

test('deleteReply deletes the comment', function () {
    Http::fake(['graph.facebook.com/*/C1*' => Http::response(['success' => true])]);

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Facebook,
        'remote_reply_id' => 'C1',
    ]);

    $result = facebookConnector()->deleteReply(facebookAccount(), $reply, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_contains($req->url(), '/C1'));
});
