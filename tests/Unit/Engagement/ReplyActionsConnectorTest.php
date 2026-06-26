<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\BlueskyEngagementConnector;
use App\Services\Engagement\Connectors\LinkedInEngagementConnector;
use App\Services\Engagement\Connectors\XEngagementConnector;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function blueskyActionConnector(): BlueskyEngagementConnector
{
    return new BlueskyEngagementConnector(app(Factory::class));
}

function xActionConnector(): XEngagementConnector
{
    return new XEngagementConnector(app(Factory::class));
}

function linkedinActionConnector(): LinkedInEngagementConnector
{
    return new LinkedInEngagementConnector(app(Factory::class));
}

$session = ['session' => ['pds' => 'https://bsky.social', 'accessJwt' => 'jwt']];

test('bluesky likeReply creates a like record and returns its uri', function () use ($session) {
    Http::fake(['*com.atproto.repo.createRecord*' => Http::response(['uri' => 'at://did/app.bsky.feed.like/abc', 'cid' => 'c'])]);

    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);
    $reply = PostTargetReply::factory()->create(['remote_reply_id' => 'at://did:plc:fan/app.bsky.feed.post/xyz', 'remote_cid' => 'cidfan']);

    $result = blueskyActionConnector()->likeReply($account, $reply, $session);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteId)->toBe('at://did/app.bsky.feed.like/abc');
    Http::assertSent(fn ($req) => str_contains($req->url(), 'createRecord')
        && $req['collection'] === 'app.bsky.feed.like'
        && $req['record']['subject']['uri'] === 'at://did:plc:fan/app.bsky.feed.post/xyz'
        && $req['record']['subject']['cid'] === 'cidfan');
});

test('bluesky unlikeReply deletes the stored like record by rkey', function () use ($session) {
    Http::fake(['*com.atproto.repo.deleteRecord*' => Http::response([])]);

    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);
    $reply = PostTargetReply::factory()->create();

    $result = blueskyActionConnector()->unlikeReply($account, $reply, 'at://did:plc:me/app.bsky.feed.like/likekey', $session);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), 'deleteRecord')
        && $req['collection'] === 'app.bsky.feed.like'
        && $req['rkey'] === 'likekey');
});

test('bluesky unlikeReply is a no-op when no like record is stored', function () use ($session) {
    Http::fake();

    $result = blueskyActionConnector()->unlikeReply(
        ConnectedAccount::factory()->bluesky()->create(),
        PostTargetReply::factory()->create(),
        null,
        $session,
    );

    expect($result->isOk())->toBeTrue();
    Http::assertNothingSent();
});

test('bluesky deleteReply deletes the post record by rkey', function () use ($session) {
    Http::fake(['*com.atproto.repo.deleteRecord*' => Http::response([])]);

    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);
    $reply = PostTargetReply::factory()->create(['remote_reply_id' => 'at://did:plc:me/app.bsky.feed.post/mine']);

    $result = blueskyActionConnector()->deleteReply($account, $reply, $session);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), 'deleteRecord')
        && $req['collection'] === 'app.bsky.feed.post'
        && $req['rkey'] === 'mine');
});

test('x likeReply posts to the user likes endpoint', function () {
    Http::fake(['*/2/users/*/likes' => Http::response(['data' => ['liked' => true]])]);

    $account = ConnectedAccount::factory()->create(['remote_account_id' => 'u1']);
    $reply = PostTargetReply::factory()->create(['remote_reply_id' => '900']);

    $result = xActionConnector()->likeReply($account, $reply, ['access_token' => 'tok']);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/2/users/u1/likes') && $req['tweet_id'] === '900');
});

test('x unlikeReply deletes from the user likes endpoint', function () {
    Http::fake(['*/2/users/u1/likes/900' => Http::response(['data' => ['liked' => false]])]);

    $account = ConnectedAccount::factory()->create(['remote_account_id' => 'u1']);
    $reply = PostTargetReply::factory()->create(['remote_reply_id' => '900']);

    $result = xActionConnector()->unlikeReply($account, $reply, null, ['access_token' => 'tok']);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_ends_with($req->url(), '/2/users/u1/likes/900'));
});

test('x deleteReply deletes the tweet', function () {
    Http::fake(['*/2/tweets/900' => Http::response(['data' => ['deleted' => true]])]);

    $account = ConnectedAccount::factory()->create(['remote_account_id' => 'u1']);
    $reply = PostTargetReply::factory()->create(['remote_reply_id' => '900']);

    $result = xActionConnector()->deleteReply($account, $reply, ['access_token' => 'tok']);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_ends_with($req->url(), '/2/tweets/900'));
});

test('x action maps 403 to unsupported', function () {
    Http::fake(['*' => Http::response(['detail' => 'no write'], 403)]);

    $result = xActionConnector()->likeReply(
        ConnectedAccount::factory()->create(['remote_account_id' => 'u1']),
        PostTargetReply::factory()->create(['remote_reply_id' => '900']),
        ['access_token' => 'tok'],
    );

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('linkedin deleteReply parses the comment id and posts a delete', function () {
    Http::fake(['*' => Http::response([])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn, 'remote_account_id' => 'PERSON']);
    $reply = PostTargetReply::factory()->create([
        'remote_reply_id' => 'urn:li:comment:(urn:li:activity:123,456)',
        'parent_remote_id' => 'urn:li:activity:123',
    ]);

    $result = linkedinActionConnector()->deleteReply($account, $reply, ['access_token' => 'tok']);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($req) => $req->method() === 'DELETE'
        && str_contains($req->url(), 'comments/456')
        && str_contains(rawurldecode($req->url()), 'urn:li:activity:123'));
});
