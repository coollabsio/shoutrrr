<?php

use App\Support\AppVersion;

afterEach(fn () => AppVersion::fake(null));

test('current reads the configured app version', function () {
    config(['app.version' => 'v2.3.4']);

    expect(AppVersion::current())->toBe('v2.3.4');
});

test('current trims surrounding whitespace from the configured version', function () {
    config(['app.version' => "  v2.3.4\n"]);

    expect(AppVersion::current())->toBe('v2.3.4');
});

test('isOutdated compares the running version against the latest tag', function () {
    AppVersion::fake('v1.4.0');

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

    // Defaults to the running version.
    AppVersion::fake('v1.3.0-rc.5');
    expect(AppVersion::isPrerelease())->toBeTrue();

    AppVersion::fake('v1.3.0');
    expect(AppVersion::isPrerelease())->toBeFalse();
});
