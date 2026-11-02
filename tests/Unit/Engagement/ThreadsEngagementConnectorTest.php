<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\ThreadsEngagementConnector;
use App\Support\ConnectionFailure;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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
        && $req['fields'] === 'status,error_message');

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

test('postReply keeps polling until the container finishes, then publishes', function () {
    Sleep::fake();

    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::sequence()
            ->push(['status' => 'IN_PROGRESS'])
            ->push(['status' => 'IN_PROGRESS'])
            ->push(['status' => 'FINISHED']),
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

    Sleep::assertSleptTimes(2);
    Http::assertSent(fn ($req) => str_contains($req->url(), '/THREADSUSER1/threads_publish')
        && $req['creation_id'] === 'CONTAINER1');
});

test('postReply returns failed when the container never finishes processing', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);

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

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 2.0, times: 6);

    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'threads_publish'));
});

test('postReply stops waiting at the deadline when status checks are slow', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);

    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => function () {
            $this->travel(3)->seconds();

            return Http::response(['status' => 'IN_PROGRESS']);
        },
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->status)->toBe(EngagementStatus::Failed);

    Sleep::assertSleptTimes(2);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'threads_publish'));
});

test('postReply bounds each request so the worst case fits the Octane limit', function () {
    Sleep::fake();

    $timeouts = [];

    Http::fake(function ($request, array $options) use (&$timeouts) {
        $step = match (true) {
            str_contains($request->url(), 'threads_publish') => 'publish',
            str_contains($request->url(), 'CONTAINER1') => 'status',
            default => 'create',
        };
        $timeouts[$step] = $options['timeout'];

        expect($options['connect_timeout'])->toBe(2);

        return Http::response(['id' => 'CONTAINER1', 'status' => 'FINISHED']);
    });

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($timeouts)->toBe(['create' => 4, 'status' => 4, 'publish' => 8]);

    // A status check may start just before the 12s wait ends.
    expect($timeouts['create'] + 12 + $timeouts['status'] + $timeouts['publish'])
        ->toBeLessThan(config('octane.max_execution_time'));
});

test('postReply keeps waiting after a status check cannot connect', function () {
    Sleep::fake();

    $checks = 0;

    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => function () use (&$checks) {
            if (++$checks === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['status' => 'FINISHED']);
        },
        'graph.threads.net/v1.0/THREADSUSER1/threads_publish' => Http::response(['id' => 'R2']),
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
});

test('postReply reports a connection failure when every status check fails', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);

    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => fn () => throw new ConnectionException('cURL error 7'),
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->message)->toBe(ConnectionFailure::MESSAGE);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'threads_publish'));
});

test('postReply does not invite a retry when the publish outcome is unknown', function () {
    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.net/v1.0/THREADSUSER1/threads_publish' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->status)->toBe(EngagementStatus::Failed);
    expect($result->message)->toContain('may have posted');
});

test('postReply falls back when Threads gives a blank reason', function () {
    Sleep::fake();

    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'ERROR', 'error_message' => '']),
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->message)->toBe('Threads could not process the reply (ERROR).');
});

test('postReply stops polling and reports why when Threads rejects the container', function () {
    Sleep::fake();

    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'ERROR', 'error_message' => 'THREADS_API__REPLY_FAILED']),
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    $result = threadsEngagementConnector()->postReply(threadsEngagementAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->status)->toBe(EngagementStatus::Failed);
    expect($result->message)->toBe('THREADS_API__REPLY_FAILED');

    Sleep::assertNeverSlept();
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
