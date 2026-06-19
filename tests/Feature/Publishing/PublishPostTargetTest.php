<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetAttempt;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;

function publishTarget(array $segments = ['hello'], string $status = 'pending'): PostTarget
{
    $post = Post::factory()->create(['status' => PostStatus::Publishing]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'sections' => $segments,
        'status' => $status,
    ]);
}

function bindConnector(PublishResult|callable $result): void
{
    $connector = new class($result) implements PublishConnector
    {
        public function __construct(private $result) {}

        public function publish(PublishContext $context): PublishResult
        {
            return is_callable($this->result) ? ($this->result)($context) : $this->result;
        }

        public function delete(PostTarget $target, array $credentials): void {}
    };

    app()->instance(PublishConnectorRegistry::class, new class($connector) extends PublishConnectorRegistry
    {
        public function __construct(private PublishConnector $connector) {}

        public function for(Platform $platform): PublishConnector
        {
            return $this->connector;
        }
    });
}

test('successful publish marks the target published with remote ids', function () {
    $target = publishTarget(['one', 'two']);
    bindConnector(PublishResult::success(['111', '222']));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('111')
        ->and($target->remote_ids)->toBe(['111', '222'])
        ->and($target->posted_at)->not->toBeNull();

    expect(PostTargetAttempt::where('post_target_id', $target->id)->where('status', 'published')->count())->toBe(1);
    expect($target->post->refresh()->status)->toBe(PostStatus::Published);
});

test('retryable failure schedules a retry and re-dispatches', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::RateLimited, 'slow', 429));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->next_attempt_at)->not->toBeNull()
        ->and($target->attempts)->toBe(1);

    Bus::assertDispatched(PublishPostTarget::class);
    expect(PostTargetAttempt::where('post_target_id', $target->id)->where('status', 'retrying')->count())->toBe(1);
});

test('rate limited retry honors the provider retry-after delay', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::RateLimited, 'slow', 429, retryAfter: 900));

    Date::setTestNow(now()->startOfSecond());
    $expected = now()->addSeconds(900);

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->next_attempt_at->equalTo($expected))->toBeTrue();

    Bus::assertDispatched(PublishPostTarget::class, function (PublishPostTarget $job): bool {
        return $job->delay === 900;
    });

    Date::setTestNow();
});

test('terminal failure marks the target failed without retry', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::Validation, 'bad', 400));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Validation);

    Bus::assertNotDispatched(PublishPostTarget::class);
    expect($target->post->refresh()->status)->toBe(PostStatus::Failed);
});

test('retry stops after five attempts', function () {
    Bus::fake();
    $target = publishTarget();
    $target->forceFill(['attempts' => 4])->save();
    bindConnector(PublishResult::failure(ErrorKind::ServerError, 'boom', 500));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('an uncaught exception closes the attempt and marks the target failed (never stuck publishing)', function () {
    $target = publishTarget();
    bindConnector(function (): never {
        throw new RuntimeException('boom');
    });

    $job = new PublishPostTarget($target);

    try {
        $job->handle(
            app(PublishConnectorRegistry::class),
            app(TokenManager::class),
            app(PostStatusRollup::class),
            app(BackoffSchedule::class),
        );
    } catch (RuntimeException) {
        // Laravel invokes failed() when the job throws.
        $job->failed(new RuntimeException('boom'));
    }

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_message)->not->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->latest('id')->first();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->finished_at)->not->toBeNull();

    expect($target->post->refresh()->status)->toBe(PostStatus::Failed);
});

test('job has tries=1 and a timeout below the queue retry_after', function () {
    $target = publishTarget();
    $job = new PublishPostTarget($target);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(900);

    // Invariant: the job timeout MUST stay below the queue connection's retry_after,
    // or a slow large-video run is released to a second worker mid-upload and double-posts.
    expect($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

test('handle is a no-op on a terminal published target (stale retry / double dispatch)', function () {
    $target = publishTarget(status: 'published');
    $target->forceFill(['remote_id' => 'rid', 'remote_ids' => ['rid']])->save();

    bindConnector(function (): never {
        throw new RuntimeException('connector must not be called');
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('rid')
        ->and($target->attempts)->toBe(0);

    expect(PostTargetAttempt::where('post_target_id', $target->id)->count())->toBe(0);
});

test('thread resumption passes already-posted ids to the connector', function () {
    $target = publishTarget(['one', 'two']);
    $target->forceFill(['remote_ids' => ['111']])->save();

    $seen = null;
    bindConnector(function (PublishContext $context) use (&$seen): PublishResult {
        $seen = $context->target->remote_ids;

        return PublishResult::success(['111', '222']);
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($seen)->toBe(['111']);
});
