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
        ['max_age_hours' => 168],
    ]);
});
