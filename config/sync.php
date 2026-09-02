<?php

declare(strict_types=1);

return [
    // Master kill switch. SYNC_ENABLED=false makes fan-out vanish at every layer.
    'enabled' => (bool) env('SYNC_ENABLED', true),

    // Reconcile backstop lookback: only re-check source targets published within
    // this window, so the sweep never rescans all history.
    'reconcile_lookback_minutes' => (int) env('SYNC_RECONCILE_LOOKBACK_MINUTES', 60),
];
