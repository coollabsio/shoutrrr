<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\PostTargetStatus;
use App\Exceptions\TokenRefreshException;
use App\Models\PostTarget;
use App\Models\PostTargetAttempt;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PublishPostTarget implements ShouldQueue
{
    use Queueable;

    private const int MAX_ATTEMPTS = 5;

    /**
     * Each publish is its own retry loop (self-dispatched delayed jobs), so the queue
     * worker must not also retry — `tries=1` keeps a transient throw from amplifying
     * into duplicate posts. Combined with the terminal-status guard in handle().
     */
    public int $tries = 1;

    public int $timeout = 120;

    private const array TERMINAL = [
        PostTargetStatus::Published,
        PostTargetStatus::Deleting,
        PostTargetStatus::Deleted,
    ];

    public function __construct(public PostTarget $target) {}

    public function handle(
        PublishConnectorRegistry $registry,
        TokenManager $tokens,
        PostStatusRollup $rollup,
        BackoffSchedule $backoff,
    ): void {
        $target = $this->target->fresh() ?? $this->target;
        $this->target = $target;

        // Guard against a stale delayed retry or a double dispatch firing after the
        // target already reached a terminal state: doing nothing keeps it a no-op.
        if (in_array($target->status, self::TERMINAL, true)) {
            return;
        }

        $attempt = DB::transaction(function () use ($target): PostTargetAttempt {
            $target->forceFill([
                'status' => PostTargetStatus::Publishing->value,
                'attempts' => $target->attempts + 1,
                // Real duplicate-prevention relies on incremental remote_ids resume (spec §4.3)
                // plus the terminal-status guard above; idempotency_key is reserved for providers
                // that support an idempotency header (X/Bluesky/LinkedIn do not uniformly today).
                'idempotency_key' => $target->idempotency_key ?? (string) Str::uuid(),
            ])->save();

            return PostTargetAttempt::create([
                'post_target_id' => $target->id,
                'attempt_no' => $target->attempts,
                'status' => 'retrying',
                'started_at' => Date::now(),
            ]);
        });

        try {
            $credentials = $tokens->fresh($target->account()->firstOrFail());
            $result = $registry->for($target->platform)->publish($this->context($target, $credentials));
        } catch (TokenRefreshException $e) {
            $result = PublishResult::failure(ErrorKind::AuthExpired, $e->getMessage());
        }

        if ($result->isSuccessful()) {
            $this->onSuccess($target, $attempt, $result);
        } else {
            $this->onFailure($target, $attempt, $result, $backoff);
        }

        $rollup->recompute($target->post()->firstOrFail());
    }

    /**
     * Runs when the job throws an uncaught exception. Closes the open attempt row and
     * marks the target terminally Failed so it is never left stuck on `publishing`.
     * An uncaught throw is unexpected, so we do NOT auto-retry it.
     */
    public function failed(Throwable $e): void
    {
        $target = $this->target->fresh() ?? $this->target;

        $attempt = $target->attemptLogs()->whereNull('finished_at')->latest('id')->first();

        $attempt?->forceFill([
            'status' => 'failed',
            'error_message' => Str::limit($e->getMessage(), 1000),
            'finished_at' => Date::now(),
        ])->save();

        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_message' => Str::limit($e->getMessage(), 1000),
            'next_attempt_at' => null,
        ])->save();

        app(PostStatusRollup::class)->recompute($target->post()->firstOrFail());
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function context(PostTarget $target, array $credentials): PublishContext
    {
        $post = $target->post()->firstOrFail();

        return new PublishContext(
            target: $target,
            segments: $target->sections,
            media: array_values($post->media()->get()->all()),
            account: $target->account()->firstOrFail(),
            credentials: $credentials,
        );
    }

    private function onSuccess(PostTarget $target, PostTargetAttempt $attempt, PublishResult $result): void
    {
        $target->forceFill([
            'status' => PostTargetStatus::Published->value,
            'remote_id' => $result->remoteIds[0] ?? null,
            'remote_ids' => $result->remoteIds,
            'posted_at' => Date::now(),
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        $attempt->forceFill([
            'status' => 'published',
            'http_status' => $result->httpStatus,
            'finished_at' => Date::now(),
        ])->save();
    }

    private function onFailure(PostTarget $target, PostTargetAttempt $attempt, PublishResult $result, BackoffSchedule $backoff): void
    {
        $kind = $result->errorKind ?? ErrorKind::Unknown;
        $canRetry = $kind->isRetryable() && $target->attempts < self::MAX_ATTEMPTS;

        $attempt->forceFill([
            'status' => $canRetry ? 'retrying' : 'failed',
            'error_kind' => $kind->value,
            'error_message' => $result->errorMessage,
            'http_status' => $result->httpStatus,
            'response_excerpt' => $result->responseExcerpt,
            'finished_at' => Date::now(),
        ])->save();

        if ($canRetry) {
            $delay = $result->retryAfter ?? $backoff->nextDelaySeconds($target->attempts);

            $target->forceFill([
                'status' => PostTargetStatus::Publishing->value,
                'error_kind' => $kind->value,
                'error_message' => $result->errorMessage,
                'next_attempt_at' => Date::now()->addSeconds($delay),
            ])->save();

            self::dispatch($target->fresh())->delay($delay);

            return;
        }

        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_kind' => $kind->value,
            'error_message' => $result->errorMessage,
            'next_attempt_at' => null,
        ])->save();
    }
}
