<?php

use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Enums\SendStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\ReplyMayHavePosted;
use App\Jobs\SendReply;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
    $this->actingAs($this->user);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
        'remote_account_id' => 'THREADSUSER1',
        'token_expires_at' => now()->addDays(30),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'threads-tok']);
    $target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $this->workspace->id]))
        ->for($account, 'account')
        ->create(['platform' => Platform::Threads, 'remote_id' => 'MEDIA1']);
    $this->reply = PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
        'remote_reply_id' => 'R1',
        'parent_remote_id' => 'MEDIA1',
    ]);
});

function queueThreadsReply(): PostTargetReply
{
    Queue::fake();

    $id = test()->postJson(route('engagement.respond', test()->reply), ['text' => 'thanks!'])
        ->assertCreated()
        ->assertJsonPath('reply.send_status', SendStatus::Sending->value)
        ->json('reply.id');

    return PostTargetReply::withoutGlobalScopes()->findOrFail($id);
}

function threadsReplyJob(PostTargetReply $ourRow, ?string $containerId = null, int $containerChecks = 0): SendReply
{
    return new SendReply($ourRow->id, test()->reply->id, [], 'thanks!', Platform::Threads, $containerId, $containerChecks);
}

function runThreadsReplyJob(SendReply $job): void
{
    $job->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class));
}

test('a new reply replaces an earlier failed attempt', function (): void {
    $attempt = fn (SendStatus $status): PostTargetReply => PostTargetReply::factory()->for($this->reply->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
        'remote_reply_id' => 'pending:'.Str::uuid(),
        'parent_remote_id' => 'R1',
        'is_ours' => true,
        'send_status' => $status,
    ]);
    $failed = $attempt(SendStatus::Failed);
    $sent = $attempt(SendStatus::Sent);

    queueThreadsReply();

    expect(PostTargetReply::withoutGlobalScopes()->find($failed->id))->toBeNull()
        ->and(PostTargetReply::withoutGlobalScopes()->find($sent->id))->not->toBeNull();
});

test('a reply that posted but could not be saved is not sent again', function (): void {
    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.net/v1.0/THREADSUSER1/threads_publish' => Http::response(['id' => 'R2']),
    ]);
    $ourRow = queueThreadsReply();
    PostTargetReply::saving(function (PostTargetReply $row): void {
        if ($row->remote_reply_id === 'R2') {
            throw new RuntimeException('database went away');
        }
    });

    $job = threadsReplyJob($ourRow)->withFakeQueueInteractions();
    runThreadsReplyJob($job);

    $job->assertFailedWith(ReplyMayHavePosted::class);
});

test('a job that failed after posting tells the user not to send again', function (): void {
    $ourRow = queueThreadsReply();

    threadsReplyJob($ourRow)->failed(new ReplyMayHavePosted(new RuntimeException('database went away')));

    expect($ourRow->fresh()->send_status)->toBe(SendStatus::Failed)
        ->and($ourRow->fresh()->send_error)->toContain('may have posted');
});

test('a text reply on threads is queued and makes no platform call in the request', function (): void {
    Http::preventStrayRequests();

    queueThreadsReply();

    Queue::assertPushed(SendReply::class, 1);
    expect($this->reply->fresh()->status)->toBe(ReplyStatus::Pending);
});

test('the job publishes with the account token and marks the reply sent', function (): void {
    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.net/v1.0/THREADSUSER1/threads_publish' => Http::response(['id' => 'R2']),
    ]);
    $ourRow = queueThreadsReply();

    runThreadsReplyJob(threadsReplyJob($ourRow));

    expect($ourRow->fresh()->send_status)->toBe(SendStatus::Sent)
        ->and($ourRow->fresh()->remote_reply_id)->toBe('R2')
        ->and($this->reply->fresh()->status)->toBe(ReplyStatus::Responded);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/THREADSUSER1/threads')
        && ! str_contains($req->url(), 'threads_publish')
        && $req['access_token'] === 'threads-tok');
});

test('a failed job keeps the reason for the inbox to show', function (): void {
    Http::fake(['graph.threads.net/*' => Http::response(['error' => ['message' => 'Reply not allowed']], 400)]);
    $ourRow = queueThreadsReply();

    runThreadsReplyJob(threadsReplyJob($ourRow));

    expect($ourRow->fresh()->send_status)->toBe(SendStatus::Failed)
        ->and($ourRow->fresh()->send_error)->toBe('Reply not allowed');

    $this->getJson(route('engagement.thread', $this->reply))
        ->assertOk()
        ->assertJsonFragment(['send_status' => 'failed', 'send_error' => 'Reply not allowed']);
});

test('a job that may have published does not report a retryable failure', function (): void {
    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.net/v1.0/THREADSUSER1/threads_publish' => fn () => throw new ConnectionException('cURL error 28'),
    ]);
    $ourRow = queueThreadsReply();

    runThreadsReplyJob(threadsReplyJob($ourRow));

    expect($ourRow->fresh()->send_error)->toContain('may have posted');
});

test('a processing container frees the worker and checks again later', function (): void {
    Sleep::fake();
    Http::fake([
        'graph.threads.net/v1.0/THREADSUSER1/threads' => Http::response(['id' => 'CONTAINER1']),
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'IN_PROGRESS']),
    ]);
    $ourRow = queueThreadsReply();

    runThreadsReplyJob(threadsReplyJob($ourRow));

    Sleep::assertNeverSlept();
    Queue::assertPushed(SendReply::class, fn (SendReply $job): bool => $job->containerId === 'CONTAINER1'
        && $job->containerChecks === 1
        && $job->delay === SendReply::CONTAINER_CHECK_DELAY_SECONDS);
    expect($ourRow->fresh()->send_status)->toBe(SendStatus::Sending);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'threads_publish'));
});

test('the follow-up job publishes the same container once', function (): void {
    Http::fake([
        'graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.net/v1.0/THREADSUSER1/threads_publish' => Http::response(['id' => 'R2']),
    ]);
    $ourRow = queueThreadsReply();

    runThreadsReplyJob(threadsReplyJob($ourRow, 'CONTAINER1', 3));

    expect($ourRow->fresh()->send_status)->toBe(SendStatus::Sent);
    Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/THREADSUSER1/threads'));
    Http::assertSentCount(2);
});

test('follow-up checks skip the send rate limit', function (): void {
    $ourRow = queueThreadsReply();

    expect(threadsReplyJob($ourRow)->middleware())->toHaveCount(1)
        ->and(threadsReplyJob($ourRow, 'CONTAINER1', 1)->middleware())->toBe([]);
});

test('the job gives up when the container never finishes', function (): void {
    Http::fake(['graph.threads.net/v1.0/CONTAINER1*' => Http::response(['status' => 'IN_PROGRESS'])]);
    $ourRow = queueThreadsReply();

    runThreadsReplyJob(threadsReplyJob($ourRow, 'CONTAINER1', SendReply::MAX_CONTAINER_CHECKS));

    Queue::assertPushed(SendReply::class, 1);
    expect($ourRow->fresh()->send_status)->toBe(SendStatus::Failed)
        ->and($ourRow->fresh()->send_error)->toContain('still processing');
});

test('a reply held back by the rate limit stays queued instead of failing', function (): void {
    config(['queue.default' => 'database']);
    RateLimiter::for('engagement-threads', fn (): Limit => Limit::perMinute(1)->by('test'));

    $id = $this->postJson(route('engagement.respond', $this->reply), ['text' => 'thanks!'])
        ->assertCreated()
        ->json('reply.id');

    foreach (range(1, 4) as $run) {
        // Use up the limit first, so this run is released back onto the queue.
        RateLimiter::hit(md5('engagement-threads'.'test'), 60);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        $this->travel(2)->minutes();
    }

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(PostTargetReply::withoutGlobalScopes()->findOrFail($id)->send_status)->toBe(SendStatus::Sending);
});
