<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\ThreadsEngagementConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function threadsEngagementConnector(): ThreadsEngagementConnector
{
    return new ThreadsEngagementConnector(app(Factory::class));
}

function threadsEngagementAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'platform' => Platform::Threads,
        'remote_account_id' => 'THREADSUSER1',
    ]);
}

test('fetchReplies maps replies to FetchedReply', function () {
    Http::fake([
        'graph.threads.net/v1.0/MEDIA1/replies*' => Http::response([
            'data' => [
                [
                    'id' => 'R1',
                    'text' => 'nice thread',
                    'username' => 'fan_one',
                    'timestamp' => '2026-07-01T12:00:00+0000',
                    'hide_status' => 'NOT_HIDDEN',
                ],
            ],
        ]),
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::Threads, 'remote_id' => 'MEDIA1']);

    $result = threadsEngagementConnector()->fetchReplies(threadsEngagementAccount(), $target, ['access_token' => 't'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(1);
    expect($result->replies[0]->remoteReplyId)->toBe('R1');
    expect($result->replies[0]->remoteCid)->toBeNull();
    expect($result->replies[0]->parentRemoteId)->toBe('MEDIA1');
    expect($result->replies[0]->authorHandle)->toBe('fan_one');
    expect($result->replies[0]->authorName)->toBe('fan_one');
    expect($result->replies[0]->authorAvatarUrl)->toBeNull();
    expect($result->replies[0]->text)->toBe('nice thread');
    expect($result->replies[0]->remoteCreatedAt)->toBeInstanceOf(CarbonImmutable::class);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/MEDIA1/replies')
        && str_contains((string) $req['fields'], 'username'));
});

test('fetchReplies maps 403 to unsupported', function () {
    Http::fake(['graph.threads.net/v1.0/MEDIA1/replies*' => Http::response(['error' => ['message' => 'no perms']], 403)]);

    $target = PostTarget::factory()->create(['platform' => Platform::Threads, 'remote_id' => 'MEDIA1']);

    $result = threadsEngagementConnector()->fetchReplies(threadsEngagementAccount(), $target, ['access_token' => 't'], null);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('postReply creates a container with reply_to_id, polls, then publishes', function () {
    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.net/v1.0/THREADSUSER1/threads_publish' => Http::response(['id' => 'R2']),
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('R2');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/THREADSUSER1/threads')
        && ! str_contains($req->url(), 'threads_publish')
        && $req['media_type'] === 'TEXT'
        && $req['text'] === 'thanks!'
        && $req['reply_to_id'] === 'R1'
        && $req['access_token'] === 't');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/CONTAINER1')
        && $req['fields'] === 'status');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/THREADSUSER1/threads_publish')
        && $req['creation_id'] === 'CONTAINER1');
});

test('postReply declines media (Threads replies cannot carry attachments in this cut)', function () {
    Http::preventStrayRequests();

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(
        threadsEngagementAccount(),
        $parent,
        'with pic',
        ['access_token' => 't'],
        [PostMedia::factory()->make()],
    );

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('postReply returns failed when the container never finishes processing', function () {
    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'IN_PROGRESS']),
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->status)->toBe(EngagementStatus::Failed);

    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'threads_publish'));
});

test('likeReply is unsupported and sends no HTTP request', function () {
    Http::preventStrayRequests();

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
    ]);

    $result = threadsEngagementConnector()->likeReply(threadsEngagementAccount(), $reply, ['access_token' => 't']);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
    expect($result->message)->toBe('Threads does not support liking replies via API');

    Http::assertNothingSent();
});

test('unlikeReply is unsupported and sends no HTTP request', function () {
    Http::preventStrayRequests();

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
    ]);

    $result = threadsEngagementConnector()->unlikeReply(threadsEngagementAccount(), $reply, null, ['access_token' => 't']);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
    expect($result->message)->toBe('Threads does not support liking replies via API');

    Http::assertNothingSent();
});

test('deleteReply deletes the reply', function () {
    Http::fake(['graph.threads.net/v1.0/R1*' => Http::response(['success' => true])]);

    $reply = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
    ]);

    $result = threadsEngagementConnector()->deleteReply(threadsEngagementAccount(), $reply, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();

    Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_contains($req->url(), '/R1'));
});
