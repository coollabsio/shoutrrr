<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EngagementStatus;
use App\Exceptions\TokenRefreshException;
use App\Jobs\Concerns\ReportsReplyFetch;
use App\Jobs\Contracts\ReleasableJob;
use App\Jobs\Middleware\ThrottlesEngagementFetch;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Engagement\ReplyPersister;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

class FetchPostTargetReplies implements ReleasableJob, ShouldBeUnique, ShouldQueue
{
    use Queueable, ReportsReplyFetch;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Hold the uniqueness lock at most this long, so a stuck worker can't block
     * the next cadence window forever.
     */
    public int $uniqueFor = 300;

    public function __construct(public PostTarget $target) {}

    /**
     * One in-flight fetch per target: overlapping scheduler ticks (or a slow
     * worker) must not double-dispatch the same target.
     */
    public function uniqueId(): string
    {
        return $this->target->id;
    }

    /**
     * Throttle outbound fetch calls per connected account so one busy account
     * can't starve the fleet or burst past the platform's own rate limit.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ThrottlesEngagementFetch($this->target->connected_account_id)];
    }

    /**
     * Back off between retries on transient (thrown) errors.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(EngagementConnectorRegistry $registry, TokenManager $tokens, ReplyPersister $persister): void
    {
        if (! config('engagement.enabled')) {
            return;
        }

        $target = $this->target->fresh();

        if ($target === null) {
            return;
        }

        $account = $target->account()->withoutGlobalScopes()->first();
        $post = $target->post()->withoutGlobalScopes()->first();

        if ($account === null || $post === null) {
            return;
        }

        $scope = "target:{$target->id}";

        try {
            $credentials = $tokens->fresh($account);
        } catch (TokenRefreshException) {
            $this->logFetchOutcome($target->platform->value, $account->id, $scope, 'token_refresh_failed');

            return;
        }

        $since = PostTargetReply::withoutGlobalScopes()
            ->where('post_target_id', $target->id)
            ->where('is_ours', false)
            ->max('remote_created_at');

        $result = $registry->for($target->platform)->fetchReplies(
            $account,
            $target,
            $credentials,
            $since !== null ? Date::parse($since)->toImmutable() : null,
        );

        if (! $result->isOk()) {
            if ($result->status === EngagementStatus::RateLimited) {
                $this->release($this->parkForRateLimit($account, $result->retryAfterSeconds));
            }

            $this->logFetchOutcome($target->platform->value, $account->id, $scope, $result->status->value, 0, $result->retryAfterSeconds);

            return;
        }

        $inserted = $persister->persist($target, $result);

        $this->logFetchOutcome(
            $target->platform->value,
            $account->id,
            $scope,
            $result->replies === [] ? 'empty' : 'ok',
            count($inserted),
        );
    }
}
