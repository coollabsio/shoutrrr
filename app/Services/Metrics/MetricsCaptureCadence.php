<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;

class MetricsCaptureCadence
{
    /** Sampling interval (seconds) for a post of the given age, or null once polling stops. */
    public function postIntervalSeconds(PostTarget $target, CarbonImmutable $now): ?int
    {
        if ($target->posted_at === null) {
            return null;
        }

        $postedAt = $target->posted_at;
        $ageHours = $postedAt->diffInHours($now);

        /** @var list<array{max_age_hours: int}> $bands */
        $bands = config('metrics.post_refresh');

        foreach ($bands as $band) {
            if ($ageHours < $band['max_age_hours']) {
                return app(InstanceSettings::class)->postMetricsPollIntervalMinutes($target->platform) * 60;
            }
        }

        return null;
    }

    public function postTargetDue(PostTarget $target, CarbonImmutable $now): bool
    {
        if ($target->posted_at === null) {
            return false;
        }

        if ($target->metrics_status !== null && ! $target->metrics_status->isPollable()) {
            return false;
        }

        $interval = $this->postIntervalSeconds($target, $now);

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
