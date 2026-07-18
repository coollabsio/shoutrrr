<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Services\Metrics\MetricsConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Date;

class CaptureAccountMetrics implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Hold the uniqueness lock at most this long, so a stuck worker can't block
     * the next cadence window forever.
     */
    public int $uniqueFor = 300;

    public function __construct(public ConnectedAccount $account) {}

    /**
     * One in-flight capture per account: overlapping scheduler ticks (or a slow
     * worker) must not double-dispatch the same account.
     */
    public function uniqueId(): string
    {
        return $this->account->id;
    }

    /**
     * Throttle outbound capture calls per platform so a large account list can't
     * trip the platform's own rate limits (which would mark accounts failed).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited("metrics-{$this->account->platform->value}")];
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

    public function handle(MetricsConnectorRegistry $registry, TokenManager $tokens, InstanceSettings $settings): void
    {
        if (! $settings->metricsEnabled()) {
            return;
        }

        $account = $this->account->fresh();

        if ($account === null || $account->isDisabled()) {
            return;
        }

        if (! $account->platform->supportsAccountMetrics()) {
            $this->record($account, MetricsStatus::Unsupported);

            return;
        }

        try {
            $credentials = in_array($account->platform, [Platform::X, Platform::Facebook, Platform::Instagram, Platform::Threads], true)
                ? $tokens->fresh($account)
                : [];
        } catch (TokenRefreshException) {
            $this->record($account, MetricsStatus::Failed);

            return;
        }

        $result = $registry->for($account->platform)->fetchAccount($account, $credentials);

        if ($result->isOk()) {
            AccountMetric::create([
                'connected_account_id' => $account->id,
                'captured_at' => Date::now(),
                'followers' => $result->followers,
                'following' => $result->following,
                'posts_count' => $result->postsCount,
                'raw' => $result->raw,
            ]);
        }

        $this->record($account, $result->status);
    }

    private function record(ConnectedAccount $account, MetricsStatus $status): void
    {
        $account->forceFill([
            'metrics_status' => $status->value,
            'metrics_captured_at' => Date::now(),
        ])->save();
    }
}
