<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Missed-post staleness window
    |--------------------------------------------------------------------------
    |
    | A scheduled post whose time passed while the scheduler/worker was down is
    | still published late (catch-up) as long as it is overdue by no more than
    | this many minutes. Beyond the window it is marked "missed" instead of
    | publishing at an arbitrarily stale moment. Defaults to two days.
    |
    */

    'missed_after_minutes' => (int) env('POSTS_MISSED_AFTER_MINUTES', 2880),

];
