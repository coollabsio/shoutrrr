<?php

declare(strict_types=1);

return [
    // Master kill switch. METRICS_ENABLED=false makes the feature vanish at every layer.
    'enabled' => (bool) env('METRICS_ENABLED', true),

    // How often each connected account's follower count is snapshotted (timeline grain).
    'account_interval_minutes' => (int) env('METRICS_ACCOUNT_INTERVAL_MINUTES', 360),

    // Decaying refresh frequency for a published post's latest totals, by post age.
    // Beyond the last band's max_age_hours, polling stops.
    'post_refresh' => [
        ['max_age_hours' => 48, 'interval_minutes' => 60],
        ['max_age_hours' => 168, 'interval_minutes' => 360],
        ['max_age_hours' => 720, 'interval_minutes' => 1440],
    ],
];
