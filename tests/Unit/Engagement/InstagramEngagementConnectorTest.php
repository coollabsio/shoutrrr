<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\InstagramEngagementConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function instagramConnector(): InstagramEngagementConnector
{
    return new InstagramEngagementConnector(app(Factory::class));
}

function instagramAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram,
        'remote_account_id' => 'IGUSER1',
    ]);
}

test('fetchReplies maps comments to FetchedReply', function () {
    Http::fake([
        'graph.facebook.com/*/MEDIA1/comments*' => Http::response([
            'data' => [
                [
                    'id' => 'C1',
                    'text' => 'nice post',
                    'username' => 'fan_one',
                    'timestamp' => '2026-07-01T12:00:00+0000',
                    'like_count' => 2,
                ],
            ],
        ]),
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => 'MEDIA1']);

    $result = instagramConnector()->fetchReplies(instagramAccount(), $target, ['access_token' => 't'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(1);
    expect($result->replies[0]->remoteReplyId)->toBe('C1');
    expect($result->replies[0]->remoteCid)->toBeNull();
    expect($result->replies[0]->parentRemoteId)->toBe('MEDIA1');
    expect($result->replies[0]->authorHandle)->toBe('fan_one');
    expect($result->replies[0]->authorName)->toBe('fan_one');
    expect($result->replies[0]->authorAvatarUrl)->toBeNull();
    expect($result->replies[0]->text)->toBe('nice post');
    expect($result->replies[0]->remoteCreatedAt)->toBeInstanceOf(CarbonImmutable::class);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/MEDIA1/comments')
        && str_contains((string) $req['fields'], 'username'));
});

test('fetchReplies maps 403 to unsupported', function () {
    Http::fake(['graph.facebook.com/*/MEDIA1/comments*' => Http::response(['error' => ['message' => 'no perms']], 403)]);

    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => 'MEDIA1']);

    $result = instagramConnector()->fetchReplies(instagramAccount(), $target, ['access_token' => 't'], null);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('postReply posts a reply and returns the id', function () {
    Http::fake(['graph.facebook.com/*/C1/replies' => Http::response(['id' => 'C2'])]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Instagram,
        'remote_reply_id' => 'C1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = instagramConnector()->postReply(instagramAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('C2');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/C1/replies')
        && $req['message'] === 'thanks!'
        && $req['access_token'] === 't');
});

test('postReply declines media (Instagram comments cannot carry attachments)', function () {
    Http::preventStrayRequests();

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Instagram,
        'remote_reply_id' => 'C1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = instagramConnector()->postReply(
        instagramAccount(),
        $parent,
        'with pic',
        ['access_token' => 't'],
        [PostMedia::factory()->make()],
    );

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('likeReply likes the comment via the user-scoped likes edge', function () {
    Http::fake(['graph.facebook.com/*/IGUSER1/likes' => Http::response(['success' => true])]);

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Instagram,
        'remote_reply_id' => 'C1',
    ]);

    $result = instagramConnector()->likeReply(instagramAccount(), $reply, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => $req->method() === 'POST'
        && str_contains($req->url(), '/IGUSER1/likes')
        && $req['comment_id'] === 'C1'
        && $req['access_token'] === 't');
});

test('likeReply maps 403 to unsupported (missing instagram_manage_engagement)', function () {
    Http::fake(['graph.facebook.com/*/IGUSER1/likes' => Http::response(['error' => ['message' => 'no perms']], 403)]);

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Instagram,
        'remote_reply_id' => 'C1',
    ]);

    $result = instagramConnector()->likeReply(instagramAccount(), $reply, ['access_token' => 't']);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
    expect($result->message)->toBe('no perms');
});

test('unlikeReply unlikes the comment via the user-scoped likes edge', function () {
    Http::fake(['graph.facebook.com/*/IGUSER1/likes' => Http::response(['success' => true])]);

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Instagram,
        'remote_reply_id' => 'C1',
    ]);

    $result = instagramConnector()->unlikeReply(instagramAccount(), $reply, null, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => $req->method() === 'DELETE'
        && str_contains($req->url(), '/IGUSER1/likes')
        && $req['comment_id'] === 'C1'
        && $req['access_token'] === 't');
});

test('deleteReply deletes the comment', function () {
    Http::fake(['graph.facebook.com/*/C1*' => Http::response(['success' => true])]);

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Instagram,
        'remote_reply_id' => 'C1',
    ]);

    $result = instagramConnector()->deleteReply(instagramAccount(), $reply, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_contains($req->url(), '/C1'));
});
