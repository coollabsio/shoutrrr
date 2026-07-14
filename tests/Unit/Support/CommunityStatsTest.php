<?php

use App\Support\AppVersion;
use App\Support\CommunityStats;
use Illuminate\Support\Facades\Cache;

test('stars returns the cached integer or null', function () {
    expect(CommunityStats::stars())->toBeNull();

    Cache::put(CommunityStats::StarsCacheKey, 1234);
    expect(CommunityStats::stars())->toBe(1234);
});

test('latestVersion returns the cached string or null', function () {
    expect(CommunityStats::latestVersion())->toBeNull();

    Cache::put(CommunityStats::LatestVersionCacheKey, 'v9.9.9');
    expect(CommunityStats::latestVersion())->toBe('v9.9.9');
});

test('updateAvailable reflects the cached latest tag versus the running version', function () {
    expect(CommunityStats::updateAvailable())->toBeFalse();

    Cache::put(CommunityStats::LatestVersionCacheKey, 'v99.0.0');
    expect(CommunityStats::updateAvailable())->toBeTrue();

    Cache::put(CommunityStats::LatestVersionCacheKey, AppVersion::current());
    expect(CommunityStats::updateAvailable())->toBeFalse();
});
