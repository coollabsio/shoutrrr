<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Services\Metrics\MetricsConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Date;

class CapturePostTargetMetrics implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Hold the uniqueness lock at most this long, so a stuck worker can't block
     * the next cadence window forever.
     */
    public int $uniqueFor = 300;

    public function __construct(public PostTarget $target) {}

    /**
     * One in-flight capture per target: overlapping scheduler ticks (or a slow
     * worker) must not double-dispatch the same target.
     */
    public function uniqueId(): string
    {
        return $this->target->id;
    }

    /**
     * Throttle outbound capture calls per platform so a large post list can't
     * trip the platform's own rate limits (which would mark targets failed).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited("metrics-{$this->target->platform->value}")];
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

        $target = $this->target->fresh();

        if ($target === null) {
            return;
        }

        $account = $target->account()->withoutGlobalScopes()->first();

        if ($account === null || $account->isDisabled()) {
            return;
        }

        try {
            $credentials = in_array($account->platform, [Platform::X, Platform::Facebook, Platform::Instagram, Platform::Threads, Platform::Discord], true)
                ? $tokens->fresh($account)
                : [];
        } catch (TokenRefreshException) {
            $this->record($target, MetricsStatus::Failed);

            return;
        }

        $result = $registry->for($target->platform)->fetchPost($account, $target, $credentials);

        if ($result->isOk()) {
            $now = Date::now();

            $unchanged = $target->metrics_captured_at !== null
                && $target->likes === $result->likes
                && $target->comments === $result->comments
                && $target->reposts === $result->reposts
                && $target->impressions === $result->impressions;

            PostTargetMetric::updateOrCreate(
                ['post_target_id' => $target->id, 'captured_at' => $now],
                [
                    'likes' => $result->likes,
                    'comments' => $result->comments,
                    'reposts' => $result->reposts,
                    'impressions' => $result->impressions,
                ],
            );

            $target->forceFill([
                'likes' => $result->likes,
                'comments' => $result->comments,
                'reposts' => $result->reposts,
                'impressions' => $result->impressions,
                'metrics_status' => $result->status->value,
                'metrics_captured_at' => $now,
                'metrics_unchanged_streak' => $unchanged ? $target->metrics_unchanged_streak + 1 : 0,
            ])->save();

            return;
        }

        $this->record($target, $result->status);
    }

    private function record(PostTarget $target, MetricsStatus $status): void
    {
        $target->forceFill([
            'metrics_status' => $status->value,
            'metrics_captured_at' => Date::now(),
        ])->save();
    }
}
