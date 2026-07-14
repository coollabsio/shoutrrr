<?php

use App\Support\CommunityStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['subscriptions.enabled' => false]);
    config(['instance.community.repo' => 'coollabsio/shoutrrr']);
});

test('the command caches stars and the latest release tag', function () {
    Http::fake([
        'api.github.com/repos/coollabsio/shoutrrr' => Http::response(['stargazers_count' => 4210]),
        'api.github.com/repos/coollabsio/shoutrrr/releases/latest' => Http::response(['tag_name' => 'v9.9.9']),
    ]);

    $this->artisan('community:refresh-stats')->assertSuccessful();

    expect(CommunityStats::stars())->toBe(4210);
    expect(CommunityStats::latestVersion())->toBe('v9.9.9');
});

test('a failed GitHub response leaves the cache untouched', function () {
    Cache::put(CommunityStats::StarsCacheKey, 100);
    Http::fake([
        'api.github.com/*' => Http::response(null, 503),
    ]);

    $this->artisan('community:refresh-stats')->assertSuccessful();

    expect(CommunityStats::stars())->toBe(100);
    expect(CommunityStats::latestVersion())->toBeNull();
});

test('the command is a no-op on cloud', function () {
    config(['subscriptions.enabled' => true]);
    Http::fake();

    $this->artisan('community:refresh-stats')->assertSuccessful();

    Http::assertNothingSent();
    expect(CommunityStats::stars())->toBeNull();
});
