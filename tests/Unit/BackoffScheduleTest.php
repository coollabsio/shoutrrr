<?php

use App\Services\Publishing\BackoffSchedule;

test('delay grows exponentially within a jittered range', function () {
    $schedule = new BackoffSchedule;

    // attempt 1 base 60: [60, 75]; attempt 2 base 120: [120, 150]; attempt 3 base 240: [240, 300].
    expect($schedule->nextDelaySeconds(1))->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(75);
    expect($schedule->nextDelaySeconds(2))->toBeGreaterThanOrEqual(120)->toBeLessThanOrEqual(150);
    expect($schedule->nextDelaySeconds(3))->toBeGreaterThanOrEqual(240)->toBeLessThanOrEqual(300);
});

test('delay is capped near one hour', function () {
    $schedule = new BackoffSchedule;

    expect($schedule->nextDelaySeconds(20))->toBeLessThanOrEqual(3600 + 360);
    expect($schedule->nextDelaySeconds(20))->toBeGreaterThanOrEqual(3600);
});
