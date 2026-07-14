<?php

use App\Support\AppVersion;

test('current reads and trims the VERSION file', function () {
    expect(AppVersion::current())
        ->toBe(trim(file_get_contents(base_path('VERSION'))))
        ->toMatch('/^v\d+\.\d+\.\d+/');
});

test('isOutdated compares the running version against the latest tag', function () {
    expect(AppVersion::isOutdated('v99.0.0'))->toBeTrue();
    expect(AppVersion::isOutdated('v0.0.1'))->toBeFalse();
    expect(AppVersion::isOutdated(AppVersion::current()))->toBeFalse();
    expect(AppVersion::isOutdated(null))->toBeFalse();
    expect(AppVersion::isOutdated(''))->toBeFalse();
});

test('isPrerelease detects prerelease suffixes and defaults to the running version', function () {
    expect(AppVersion::isPrerelease('v1.3.0-rc.5'))->toBeTrue();
    expect(AppVersion::isPrerelease('1.4.0-beta.1'))->toBeTrue();
    expect(AppVersion::isPrerelease('v1.3.0'))->toBeFalse();
    expect(AppVersion::isPrerelease('1.2.3'))->toBeFalse();
    // Defaults to the running version, which is a prerelease (v1.3.0-rc.5).
    expect(AppVersion::isPrerelease())->toBeTrue();
});
