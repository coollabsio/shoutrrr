<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Jobs\Contracts\ReleasableJob;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-account throttle for outbound reply-fetch jobs. Platforms meter these
 * endpoints per user token, so the budget is keyed by connected account (not
 * globally per platform): one busy account can no longer starve the rest of the
 * fleet, and a large account/post list can't burst past the platform's own limit.
 */
class ThrottlesEngagementFetch
{
    public function __construct(private readonly string $accountId) {}

    public function handle(ReleasableJob $job, Closure $next): mixed
    {
        $key = "engagement-fetch:{$this->accountId}";
        $max = max(1, (int) config('engagement.fetch_rate_per_minute', 12));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return $job->release(RateLimiter::availableIn($key) + 1);
        }

        RateLimiter::hit($key, 60);

        return $next($job);
    }
}
