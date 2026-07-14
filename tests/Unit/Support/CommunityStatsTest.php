<?php

use App\Support\AppVersion;
use App\Support\CommunityStats;
use Illuminate\Support\Facades\Cache;

test('stars returns the cached integer or null', function () {
    expect(CommunityStats::stars())->toBeNull();

    Cache::put(CommunityStats::StarsCacheKey, 1234);
    expect(CommunityStats::stars())->toBe(1234);
});

test('selectLatest returns the overall tag on a prerelease channel and the stable tag otherwise', function () {
    expect(CommunityStats::selectLatest(true, 'v1.3.0', 'v1.4.0-rc.1'))->toBe('v1.4.0-rc.1');
    expect(CommunityStats::selectLatest(false, 'v1.3.0', 'v1.4.0-rc.1'))->toBe('v1.3.0');
    expect(CommunityStats::selectLatest(true, null, null))->toBeNull();
    expect(CommunityStats::selectLatest(false, null, 'v1.4.0-rc.1'))->toBeNull();
});

test('latestVersion follows the running channel (prerelease reads the overall key)', function () {
    expect(AppVersion::isPrerelease())->toBeTrue();
    expect(CommunityStats::latestVersion())->toBeNull();

    Cache::put(CommunityStats::LatestOverallCacheKey, 'v9.9.9');
    expect(CommunityStats::latestVersion())->toBe('v9.9.9');
});

test('updateAvailable reflects the overall tag versus the running prerelease', function () {
    expect(CommunityStats::updateAvailable())->toBeFalse();

    Cache::put(CommunityStats::LatestOverallCacheKey, 'v99.0.0');
    expect(CommunityStats::updateAvailable())->toBeTrue();

    Cache::put(CommunityStats::LatestOverallCacheKey, AppVersion::current());
    expect(CommunityStats::updateAvailable())->toBeFalse();
});
