<?php

declare(strict_types=1);

return [
    // Master kill switch. REPOST_ENABLED=false makes auto-repost vanish at every layer.
    'enabled' => (bool) env('REPOST_ENABLED', true),

    // Never first-boost posts older than this on the initial rollout / after downtime,
    // so enabling the feature doesn't retro-boost a backlog of old posts.
    'max_backfill_days' => (int) env('REPOST_MAX_BACKFILL_DAYS', 30),

    // Per-account defaults, overridden per key by capabilities['auto_repost'].
    'defaults' => [
        'min_delay_hours' => (int) env('REPOST_MIN_DELAY_HOURS', 24),
        'max_delay_hours' => (int) env('REPOST_MAX_DELAY_HOURS', 168),
        'plateau_streak' => (int) env('REPOST_PLATEAU_STREAK', 2),
        'min_percentile' => (float) env('REPOST_MIN_PERCENTILE', 0.5),
    ],

    // Performance-gate baseline: compare against same account+platform posts from the
    // last `window_days` that are at least `min_delay_hours` old; below `min_samples`
    // baseline posts we cold-start (boost anything with engagement > 0).
    'baseline' => [
        'window_days' => (int) env('REPOST_BASELINE_WINDOW_DAYS', 90),
        'min_samples' => (int) env('REPOST_BASELINE_MIN_SAMPLES', 5),
    ],
];
