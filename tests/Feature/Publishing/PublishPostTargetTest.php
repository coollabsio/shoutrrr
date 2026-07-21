<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\PostTargetAttempt;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;

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

test('publish fails immediately when the account already needs attention', function () {
    Bus::fake();
    $target = publishTarget();
    $target->account()->firstOrFail()->forceFill([
        'status' => ConnectedAccountStatus::NeedsAttention->value,
        'refresh_failed_at' => now(),
        'refresh_failure_reason' => 'X rejected the refresh token.',
    ])->save();

    bindConnector(fn () => throw new RuntimeException('connector should not be called'));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::AuthExpired)
        ->and($target->error_message)->toBe('X account needs attention. Reconnect it before publishing.')
        ->and($target->attempts)->toBe(1)
        ->and($target->next_attempt_at)->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->sole();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->error_kind)->toBe(ErrorKind::AuthExpired);

    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('auth expired result refreshes credentials once and retries the connector', function () {
    $target = publishTarget();
    $target->account()->firstOrFail()->secret()->firstOrFail()->forceFill([
        'refresh_token' => 'refresh-old',
    ])->save();

    config()->set('services.x.client_id', 'client-id');
    config()->set('services.x.client_secret', 'client-secret');
    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([
            'access_token' => 'fresh-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 7200,
        ]),
    ]);

    $tokens = [];
    bindConnector(function (PublishContext $context) use (&$tokens): PublishResult {
        $tokens[] = $context->credentials['access_token'];

        return count($tokens) === 1
            ? PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401)
            : PublishResult::success(['111']);
    });

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($tokens)->toBe(['tok', 'fresh-token'])
        ->and($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->attempts)->toBe(1)
        ->and($target->account()->firstOrFail()->status)->toBe(ConnectedAccountStatus::Active);

    Http::assertSentCount(1);
});

test('auth expired after the recovery refresh marks the target failed without retrying', function () {
    Bus::fake();
    $target = publishTarget();
    $target->account()->firstOrFail()->secret()->firstOrFail()->forceFill([
        'refresh_token' => 'refresh-old',
    ])->save();
    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([], 400),
    ]);
    bindConnector(PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::AuthExpired)
        ->and($target->error_message)->toStartWith('Token refresh failed for account ')
        ->and($target->attempts)->toBe(1)
        ->and($target->next_attempt_at)->toBeNull();
    expect($target->account()->firstOrFail()->status)->toBe(ConnectedAccountStatus::NeedsAttention);

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->sole();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->attempt_no)->toBe(1)
        ->and($attempt->error_kind)->toBe(ErrorKind::AuthExpired);

    Bus::assertNotDispatched(PublishPostTarget::class);
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
