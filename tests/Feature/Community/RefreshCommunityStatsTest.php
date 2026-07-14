<?php

use App\Support\CommunityStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['subscriptions.enabled' => false]);
    config(['instance.community.repo' => 'coollabsio/shoutrrr']);
});

test('the command caches stars, newest stable, and newest overall release tags', function () {
    Http::fake([
        'api.github.com/repos/coollabsio/shoutrrr' => Http::response(['stargazers_count' => 4210]),
        'api.github.com/repos/coollabsio/shoutrrr/releases*' => Http::response([
            ['tag_name' => 'v1.4.0-rc.1', 'prerelease' => true, 'draft' => false],
            ['tag_name' => 'v1.3.0', 'prerelease' => false, 'draft' => false],
            ['tag_name' => 'v1.3.0-rc.6', 'prerelease' => true, 'draft' => false],
            ['tag_name' => 'v9.9.9-draft', 'prerelease' => true, 'draft' => true],
        ]),
    ]);

    $this->artisan('community:refresh-stats')->assertSuccessful();

    expect(CommunityStats::stars())->toBe(4210);
    expect(Cache::get(CommunityStats::LatestStableCacheKey))->toBe('v1.3.0');
    expect(Cache::get(CommunityStats::LatestOverallCacheKey))->toBe('v1.4.0-rc.1');
});

test('a shipped stable release supersedes its own prerelease as the overall newest', function () {
    Http::fake([
        'api.github.com/repos/coollabsio/shoutrrr' => Http::response(['stargazers_count' => 1]),
        'api.github.com/repos/coollabsio/shoutrrr/releases*' => Http::response([
            ['tag_name' => 'v1.3.0-rc.6', 'prerelease' => true, 'draft' => false],
            ['tag_name' => 'v1.3.0', 'prerelease' => false, 'draft' => false],
        ]),
    ]);

    $this->artisan('community:refresh-stats')->assertSuccessful();

    // Never recommend the superseded rc: overall must be the real v1.3.0.
    expect(Cache::get(CommunityStats::LatestOverallCacheKey))->toBe('v1.3.0');
    expect(Cache::get(CommunityStats::LatestStableCacheKey))->toBe('v1.3.0');
});

test('with only prereleases, stable stays null and overall is the newest prerelease', function () {
    Http::fake([
        'api.github.com/repos/coollabsio/shoutrrr' => Http::response(['stargazers_count' => 1]),
        'api.github.com/repos/coollabsio/shoutrrr/releases*' => Http::response([
            ['tag_name' => 'v1.3.0-rc.5', 'prerelease' => true, 'draft' => false],
            ['tag_name' => 'v1.3.0-rc.6', 'prerelease' => true, 'draft' => false],
        ]),
    ]);

    $this->artisan('community:refresh-stats')->assertSuccessful();

    expect(Cache::get(CommunityStats::LatestStableCacheKey))->toBeNull();
    expect(Cache::get(CommunityStats::LatestOverallCacheKey))->toBe('v1.3.0-rc.6');
});

test('a failed GitHub response leaves the cache untouched', function () {
    Cache::put(CommunityStats::StarsCacheKey, 100);
    Http::fake([
        'api.github.com/*' => Http::response(null, 503),
    ]);

    $this->artisan('community:refresh-stats')->assertSuccessful();

    expect(CommunityStats::stars())->toBe(100);
    expect(Cache::get(CommunityStats::LatestStableCacheKey))->toBeNull();
    expect(Cache::get(CommunityStats::LatestOverallCacheKey))->toBeNull();
});

test('the command is a no-op on cloud', function () {
    config(['subscriptions.enabled' => true]);
    Http::fake();

    $this->artisan('community:refresh-stats')->assertSuccessful();

    Http::assertNothingSent();
    expect(CommunityStats::stars())->toBeNull();
});
