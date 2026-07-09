<?php

declare(strict_types=1);

return [
    // Master kill switch. METRICS_ENABLED=false makes the feature vanish at every layer.
    'enabled' => (bool) env('METRICS_ENABLED', true),

    // How long published posts remain eligible for metrics polling.
    // Beyond the last band's max_age_hours, polling stops.
    'post_refresh' => [
        ['max_age_hours' => 168],
    ],
];
