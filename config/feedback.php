<?php

declare(strict_types=1);

return [
    // Master kill switch. FEEDBACK_ENABLED=false hides the widget and 404s the endpoint.
    'enabled' => (bool) env('FEEDBACK_ENABLED', false),

    // Discord webhook the reports are delivered to. Feature stays off until this is set.
    'webhook_url' => env('FEEDBACK_DISCORD_WEBHOOK_URL'),
];
