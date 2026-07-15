<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Dto\Engagement\ReplyFetchResult;
use App\Enums\EngagementStatus;
use App\Enums\PostTargetStatus;
use App\Exceptions\TokenRefreshException;
use App\Jobs\Concerns\ReportsReplyFetch;
use App\Jobs\Contracts\ReleasableJob;
use App\Jobs\Middleware\ThrottlesEngagementFetch;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Contracts\BatchEngagementConnector;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Engagement\ReplyFetchCadence;
use App\Services\Engagement\ReplyPersister;
use App\Services\Publishing\TokenManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Fetches replies for all of one account's due posts in a single batched call
 * (for connectors that support it, i.e. X). Turns per-post polling from O(posts)
 * into O(posts ÷ batch), which is what makes X viable at thousands of accounts.
 */
class FetchAccountReplies implements ReleasableJob, ShouldBeUnique, ShouldQueue
{
    use Queueable, ReportsReplyFetch;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public ConnectedAccount $account) {}

    public function uniqueId(): string
    {
        return $this->account->id;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ThrottlesEngagementFetch($this->account->id)];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        EngagementConnectorRegistry $registry,
        TokenManager $tokens,
        ReplyPersister $persister,
        ReplyFetchCadence $cadence,
    ): void {
        if (! config('engagement.enabled')) {
            return;
        }

        // Reload without the workspace scope: this job runs with no workspace
        // context, so a scoped fresh() would resolve to null.
        $account = ConnectedAccount::withoutGlobalScopes()->whereKey($this->account->getKey())->first();

        if ($account === null) {
            return;
        }

        $connector = $registry->for($account->platform);

        if (! $connector instanceof BatchEngagementConnector) {
            return;
        }

        $now = Date::now()->toImmutable();

        $due = PostTarget::query()
            ->where('connected_account_id', $account->id)
            ->where('platform', $account->platform->value)
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('remote_id')
            ->whereNotNull('posted_at')
            ->get()
            ->filter(fn (PostTarget $target): bool => $cadence->isDue($target, $now))
            ->values();

        if ($due->isEmpty()) {
            return;
        }

        try {
            $credentials = $tokens->fresh($account);
        } catch (TokenRefreshException) {
            $this->logFetchOutcome($account->platform->value, $account->id, 'account', 'token_refresh_failed');

            return;
        }

        /** @var array<string, string|null> $latestByTarget */
        $latestByTarget = PostTargetReply::withoutGlobalScopes()
            ->whereIn('post_target_id', $due->pluck('id'))
            ->where('is_ours', false)
            ->selectRaw('post_target_id, max(remote_created_at) as latest')
            ->groupBy('post_target_id')
            ->pluck('latest', 'post_target_id')
            ->all();

        /** @var array<string, PostTarget> $targetByRoot */
        $targetByRoot = [];
        foreach ($due as $target) {
            $rootId = $target->remote_ids[0] ?? $target->remote_id;

            if ($rootId !== null) {
                $targetByRoot[(string) $rootId] = $target;
            }
        }

        $results = $connector->fetchRepliesForConversations(
            $account,
            array_keys($targetByRoot),
            $credentials,
            $this->batchSince($due, $latestByTarget),
        );

        foreach ($targetByRoot as $rootId => $target) {
            $result = $results[$rootId] ?? ReplyFetchResult::failed('No result returned for conversation.');

            if (! $result->isOk()) {
                // A rate-limit is account-wide (shared token) — stop the whole batch
                // and park the account rather than churning the other targets.
                if ($result->status === EngagementStatus::RateLimited) {
                    $this->logFetchOutcome($account->platform->value, $account->id, 'account', 'rate_limited', 0, $result->retryAfterSeconds);
                    $this->release($this->parkForRateLimit($account, $result->retryAfterSeconds));

                    return;
                }

                $this->logFetchOutcome($account->platform->value, $account->id, "target:{$target->id}", $result->status->value, 0, $result->retryAfterSeconds);

                continue;
            }

            $inserted = $persister->persist($target, $result);

            $this->logFetchOutcome(
                $account->platform->value,
                $account->id,
                "target:{$target->id}",
                $result->replies === [] ? 'empty' : 'ok',
                count($inserted),
            );
        }
    }

    /**
     * The oldest per-target "since" across the batch (a single query uses one
     * start_time). If any due target has never been fetched, poll the full window
     * so nothing is missed; per-target upsert dedupes the overlap.
     *
     * @param  Collection<int, PostTarget>  $due
     * @param  array<string, string|null>  $latestByTarget
     */
    private function batchSince($due, array $latestByTarget): ?CarbonImmutable
    {
        $sinces = [];

        foreach ($due as $target) {
            $latest = $latestByTarget[$target->id] ?? null;

            if ($latest !== null) {
                $sinces[] = Date::parse($latest)->toImmutable();

                continue;
            }

            // No replies yet. If the target was already polled (just empty), floor
            // at its last fetch; only a never-fetched target forces the full window.
            if ($target->reply_fetched_at === null) {
                return null;
            }

            $sinces[] = $target->reply_fetched_at;
        }

        return $sinces === [] ? null : min($sinces);
    }
}
