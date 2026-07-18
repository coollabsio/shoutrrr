<?php

use App\Support\InstanceSettings;

it('defaults metrics enabled to the metrics.enabled config value', function () {
    config(['metrics.enabled' => true]);
    expect(app(InstanceSettings::class)->metricsEnabled())->toBeTrue();

    config(['metrics.enabled' => false]);
    expect(app(InstanceSettings::class)->metricsEnabled())->toBeFalse();
});

it('lets a persisted override take precedence over the metrics config default', function () {
    config(['metrics.enabled' => true]);
    app(InstanceSettings::class)->update(['metrics_enabled' => false]);

    expect(app(InstanceSettings::class)->metricsEnabled())->toBeFalse();
});

it('defaults engagement enabled to the engagement.enabled config value', function () {
    config(['engagement.enabled' => true]);
    expect(app(InstanceSettings::class)->engagementEnabled())->toBeTrue();

    config(['engagement.enabled' => false]);
    expect(app(InstanceSettings::class)->engagementEnabled())->toBeFalse();
});

it('lets a persisted override take precedence over the engagement config default', function () {
    config(['engagement.enabled' => true]);
    app(InstanceSettings::class)->update(['engagement_enabled' => false]);

    expect(app(InstanceSettings::class)->engagementEnabled())->toBeFalse();
});

it('includes both master switches in the polling settings array', function () {
    expect(app(InstanceSettings::class)->polling())
        ->toHaveKeys(['metrics_enabled', 'engagement_enabled']);
});
