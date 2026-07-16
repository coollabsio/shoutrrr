<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;

class MetricsCaptureCadence
{
    /** Age-banded sampling interval (seconds), clamped to the platform floor, or null once polling stops. */
    public function postIntervalSeconds(PostTarget $target, CarbonImmutable $now): ?int
    {
        if (! app(InstanceSettings::class)->postMetricsPollingEnabled($target->platform)) {
            return null;
        }

        if ($target->posted_at === null) {
            return null;
        }

        $postedAt = $target->posted_at;
        $ageHours = $postedAt->diffInHours($now);
        $floor = app(InstanceSettings::class)->postMetricsPollIntervalMinutes($target->platform);

        /** @var list<array{max_age_hours: int, interval_minutes: int}> $bands */
        $bands = config('metrics.post_refresh');

        foreach ($bands as $band) {
            if ($ageHours < $band['max_age_hours']) {
                return max($band['interval_minutes'], $floor) * 60;
            }
        }

        return null;
    }

    /** Base band interval widened by the consecutive-unchanged streak, capped. Null once polling stops. */
    public function effectiveIntervalSeconds(PostTarget $target, CarbonImmutable $now): ?int
    {
        $base = $this->postIntervalSeconds($target, $now);

        if ($base === null) {
            return null;
        }

        $cap = (int) config('metrics.max_unchanged_backoff', 8);
        $multiplier = min(2 ** max(0, $target->metrics_unchanged_streak), $cap);

        return $base * $multiplier;
    }

    public function postTargetDue(PostTarget $target, CarbonImmutable $now): bool
    {
        if ($target->posted_at === null) {
            return false;
        }

        if ($target->metrics_status !== null && ! $target->metrics_status->isPollable()) {
            return false;
        }

        $interval = $this->effectiveIntervalSeconds($target, $now);

        if ($interval === null) {
            return false;
        }

        if ($target->metrics_captured_at === null) {
            return true;
        }

        return $target->metrics_captured_at->addSeconds($interval)->lte($now);
    }

    public function accountDue(ConnectedAccount $account, CarbonImmutable $now): bool
    {
        // Discord (and any other platform without account metrics) must never be
        // scheduled for follower capture.
        if (! $account->platform->supportsAccountMetrics()) {
            return false;
        }

        if (! app(InstanceSettings::class)->accountMetricsPollingEnabled($account->platform)) {
            return false;
        }

        if ($account->metrics_status !== null && ! $account->metrics_status->isPollable()) {
            return false;
        }

        if ($account->metrics_captured_at === null) {
            return true;
        }

        $interval = app(InstanceSettings::class)->accountMetricsPollIntervalMinutes($account->platform) * 60;

        return $account->metrics_captured_at->addSeconds($interval)->lte($now);
    }
}
