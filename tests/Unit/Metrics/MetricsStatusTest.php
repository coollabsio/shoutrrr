<?php

use App\Enums\MetricsStatus;

test('unsupported is the only terminal (non-pollable) status', function () {
    expect(MetricsStatus::Unsupported->isPollable())->toBeFalse();
    expect(MetricsStatus::Ok->isPollable())->toBeTrue();
    expect(MetricsStatus::RateLimited->isPollable())->toBeTrue();
    expect(MetricsStatus::Failed->isPollable())->toBeTrue();
});

test('metrics config exposes flag and cadence', function () {
    expect(config('metrics.enabled'))->toBeBool();
    expect(config('metrics.post_refresh'))->toBe([
        ['max_age_hours' => 6, 'interval_minutes' => 60],
        ['max_age_hours' => 24, 'interval_minutes' => 180],
        ['max_age_hours' => 72, 'interval_minutes' => 720],
        ['max_age_hours' => 168, 'interval_minutes' => 1440],
    ]);
    expect(config('metrics.max_unchanged_backoff'))->toBe(8);
});
