<?php

declare(strict_types=1);

return [
    // Raw usage_events older than this are pruned (durable totals live in the counters).
    'retention_days' => (int) env('USAGE_RETENTION_DAYS', 180),
];
