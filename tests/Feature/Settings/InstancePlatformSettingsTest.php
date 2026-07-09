<?php

use App\Enums\Platform;
use App\Support\InstanceSettings;

it('defaults every platform to available', function () {
    $settings = app(InstanceSettings::class);

    expect($settings->platformAvailable(Platform::X))->toBeTrue();
    expect($settings->platformsEnabled())->toBe([
        'x' => true,
        'bluesky' => true,
        'linkedin' => true,
        'facebook' => true,
        'instagram' => true,
        'threads' => true,
    ]);
});

it('freezes a single platform while leaving the rest available', function () {
    $settings = app(InstanceSettings::class);
    $settings->update(['platforms_enabled' => ['x' => false]]);

    expect($settings->platformAvailable(Platform::X))->toBeFalse();
    expect($settings->platformAvailable(Platform::Bluesky))->toBeTrue();
});

it('stops polling for a frozen platform regardless of the polling toggle', function () {
    $settings = app(InstanceSettings::class);
    $settings->update([
        'engagement_polling_enabled' => ['x' => true],
        'platforms_enabled' => ['x' => false],
    ]);

    expect($settings->engagementPollingEnabled(Platform::X))->toBeFalse();
});
