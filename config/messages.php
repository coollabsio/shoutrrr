<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('MESSAGES_ENABLED', false),

    // Instance-level opt-in that DM OAuth scopes may be requested at connect/re-auth.
    'direct_messages_enabled' => (bool) env('DIRECT_MESSAGES_ENABLED', false),

    // Per-account poll interval floor (minutes), keyed by platform value.
    // X is billable per poll, so its floor is deliberately high.
    'poll_interval_minutes' => [
        'x' => (int) env('MESSAGES_POLL_INTERVAL_X', 30),
        'bluesky' => (int) env('MESSAGES_POLL_INTERVAL_BLUESKY', 10),
        'instagram' => (int) env('MESSAGES_POLL_INTERVAL_INSTAGRAM', 15),
        'facebook' => (int) env('MESSAGES_POLL_INTERVAL_FACEBOOK', 15),
    ],

    'default_rate_limit_backoff' => (int) env('MESSAGES_DEFAULT_RATE_LIMIT_BACKOFF', 900),
    'fetch_rate_per_minute' => (int) env('MESSAGES_FETCH_RATE_PER_MINUTE', 12),

    // Meta standard messaging window in hours.
    'meta_window_hours' => 24,
];
