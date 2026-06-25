<?php

declare(strict_types=1);

return [
    // Master kill switch. ENGAGEMENT_ENABLED=false makes the feature vanish at every layer.
    'enabled' => (bool) env('ENGAGEMENT_ENABLED', true),

    // Only posts published within this many days are polled for new replies.
    'window_days' => (int) env('ENGAGEMENT_WINDOW_DAYS', 14),
];
