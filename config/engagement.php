<?php

declare(strict_types=1);

return [
    // Master kill switch. ENGAGEMENT_ENABLED=false makes the feature vanish at every layer.
    'enabled' => (bool) env('ENGAGEMENT_ENABLED', true),

    // Only posts published within this many days are polled for new replies.
    // Retained for other call sites; the reply dispatcher now bounds its working
    // set by fetch staleness (see reply_refresh) rather than a hard age cutoff.
    'window_days' => (int) env('ENGAGEMENT_WINDOW_DAYS', 7),

    // Age-banded reply polling. A post is polled at `interval_minutes` while its
    // age is under `max_age_hours`. Past the last band it does NOT stop — it drops
    // to `steady_interval_minutes` forever, so new replies on old posts still show up.
    'reply_refresh' => [
        ['max_age_hours' => 24, 'interval_minutes' => 30],
        ['max_age_hours' => 72, 'interval_minutes' => 120],
        ['max_age_hours' => 168, 'interval_minutes' => 480],
    ],

    // Steady tail cadence for posts older than the last band. We slow down, never
    // stop — engagement stays visible on old posts (default: once a day).
    'steady_interval_minutes' => (int) env('ENGAGEMENT_STEADY_INTERVAL_MINUTES', 1440),

    // Each consecutive empty fetch multiplies the effective interval, capped here,
    // so a post that keeps returning nothing is polled ever less often — but never zero.
    'max_empty_backoff' => (int) env('ENGAGEMENT_MAX_EMPTY_BACKOFF', 8),

    // Proactive per-connected-account budget for outbound reply-fetch calls. Kept
    // well under the platforms' per-user limits; the reactive parking (below) still
    // honors whatever the platform actually reports on a 429.
    'fetch_rate_per_minute' => (int) env('ENGAGEMENT_FETCH_RATE_PER_MINUTE', 12),

    // Fallback park duration (seconds) when a platform rate-limits us without a
    // usable Retry-After / reset header.
    'default_rate_limit_backoff' => (int) env('ENGAGEMENT_DEFAULT_RATE_LIMIT_BACKOFF', 900),
];
