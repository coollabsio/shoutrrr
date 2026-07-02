<?php

use App\Support\InstanceSettings;

it('defaults usage tracking to off', function () {
    expect(app(InstanceSettings::class)->usageTrackingEnabled())->toBeFalse();
});

it('reflects the config default when flipped on', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    expect(app(InstanceSettings::class)->usageTrackingEnabled())->toBeTrue();
});

it('includes the toggle in the settings array', function () {
    expect(app(InstanceSettings::class)->all())->toHaveKey('usage_tracking_enabled');
});
