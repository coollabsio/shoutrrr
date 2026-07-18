<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Shared reply-fetch bookkeeping for the fetch jobs: parking an account after a
 * rate-limit, and emitting a structured outcome log so otherwise-silent fetch
 * failures (auth expiry, unsupported tier, 429s) are visible at fleet scale.
 */
trait ReportsReplyFetch
{
    /**
     * Record how long the platform wants us to wait, so the dispatcher stops
     * queueing this account until then. Returns the delay (seconds) to release by.
     */
    protected function parkForRateLimit(ConnectedAccount $account, ?int $retryAfterSeconds): int
    {
        $seconds = $retryAfterSeconds ?? (int) config('engagement.default_rate_limit_backoff', 900);

        $account->forceFill([
            'engagement_rate_limited_until' => Date::now()->addSeconds($seconds),
        ])->saveQuietly();

        return $seconds;
    }

    protected function logFetchOutcome(
        string $platform,
        string $accountId,
        string $scope,
        string $outcome,
        int $inserted = 0,
        ?int $retryAfter = null,
    ): void {
        Log::info('engagement.fetch', [
            'platform' => $platform,
            'account_id' => $accountId,
            'scope' => $scope,
            'outcome' => $outcome,
            'inserted' => $inserted,
            'retry_after' => $retryAfter,
        ]);
    }
}
