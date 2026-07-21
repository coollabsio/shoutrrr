<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\CommunityStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

test('the three update props resolve updateData once per request', function () {
    config(['subscriptions.enabled' => false]);

    $user = User::factory()->create();
    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);

    $middleware = app(HandleInertiaRequests::class);

    // Build the shared props with the real cache so settings/features resolve.
    $shared = $middleware->share($request);

    // Start counting only the deferred update resolution.
    Cache::spy();

    ($shared['updateAvailable'])();
    ($shared['latestVersion'])();
    ($shared['latestReleaseUrl'])();

    // Without the memo each prop calls updateData() -> two community-key reads
    // (3 props => 3 reads/key). With the memo, updateData() runs once => one read/key.
    Cache::shouldHaveReceived('get')->with(CommunityStats::LatestStableCacheKey)->once();
    Cache::shouldHaveReceived('get')->with(CommunityStats::LatestOverallCacheKey)->once();
});
