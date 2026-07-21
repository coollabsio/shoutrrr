<?php

declare(strict_types=1);

return [
    // Master kill switch. METRICS_ENABLED=false makes the feature vanish at every layer.
    'enabled' => (bool) env('METRICS_ENABLED', true),

    // Age-banded post-metrics polling. A post is polled at `interval_minutes`
    // (widened to the operator's per-platform floor, see InstanceSettings) while
    // its age is under `max_age_hours`. Beyond the last band's max_age_hours,
    // polling stops for good — unlike engagement, metrics don't keep a steady
    // tail, since a frozen old post's totals aren't worth paying to re-read.
    'post_refresh' => [
        ['max_age_hours' => 6, 'interval_minutes' => 60],
        ['max_age_hours' => 24, 'interval_minutes' => 180],
        ['max_age_hours' => 72, 'interval_minutes' => 720],
        ['max_age_hours' => 168, 'interval_minutes' => 1440],
    ],

    // Each consecutive read that returns identical totals multiplies the
    // effective interval, capped here — a post whose metrics have settled is
    // polled ever less often (but never zero, until the hard stop above).
    'max_unchanged_backoff' => (int) env('METRICS_MAX_UNCHANGED_BACKOFF', 8),
];
