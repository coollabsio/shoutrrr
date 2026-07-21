<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\PostTarget;
use App\Services\Metrics\MetricsCaptureCadence;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;

/**
 * Decides when a published target is due for another reply fetch.
 *
 * Shaped like {@see MetricsCaptureCadence} but with a steady
 * tail instead of a stop: past the last age band a post keeps polling at
 * `steady_interval_minutes` forever, so late replies on old posts still surface.
 */
class ReplyFetchCadence
{
    public function __construct(private readonly InstanceSettings $settings) {}

    /**
     * Base interval (minutes) for the post's current age band. Returns null only
     * when polling is off for the platform or the post has no `posted_at` — never
     * because the post is "too old": past the last band it drops to the steady
     * tail interval and keeps polling.
     */
    public function intervalMinutes(PostTarget $target, CarbonImmutable $now): ?int
    {
        if ($target->posted_at === null) {
            return null;
        }

        if (! $this->settings->engagementPollingEnabled($target->platform)) {
            return null;
        }

        $ageHours = $target->posted_at->diffInHours($now);
        $floor = $this->settings->engagementPollIntervalMinutes($target->platform);

        /** @var list<array{max_age_hours: int, interval_minutes: int}> $bands */
        $bands = config('engagement.reply_refresh');

        foreach ($bands as $band) {
            if ($ageHours < $band['max_age_hours']) {
                // Respect the operator's per-platform floor (e.g. X = 360m).
                return max($band['interval_minutes'], $floor);
            }
        }

        // Older than every band: keep polling at the steady daily tail. We slow
        // down, we never stop — engagement stays visible on old posts.
        return max((int) config('engagement.steady_interval_minutes', 1440), $floor);
    }

    /**
     * Band interval widened by the consecutive-empty streak, capped so a post that
     * keeps returning nothing is polled ever less often — but never zero.
     */
    public function effectiveIntervalMinutes(PostTarget $target, CarbonImmutable $now): ?int
    {
        $base = $this->intervalMinutes($target, $now);

        if ($base === null) {
            return null;
        }

        $cap = (int) config('engagement.max_empty_backoff', 8);
        $multiplier = min(2 ** max(0, $target->reply_fetch_empty_streak), $cap);

        return $base * $multiplier;
    }

    public function isDue(PostTarget $target, CarbonImmutable $now): bool
    {
        $interval = $this->effectiveIntervalMinutes($target, $now);

        if ($interval === null) {
            return false;
        }

        if ($target->reply_fetched_at === null) {
            return true;
        }

        return $target->reply_fetched_at->addMinutes($interval)->lte($now);
    }
}
