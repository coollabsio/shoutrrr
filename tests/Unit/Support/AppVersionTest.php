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
