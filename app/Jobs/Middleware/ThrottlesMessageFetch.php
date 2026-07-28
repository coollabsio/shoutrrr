<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Jobs\Contracts\ReleasableJob;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-account throttle for direct-message fetch jobs. Sibling of
 * ThrottlesEngagementFetch, but keyed separately so DM polling and engagement
 * reply-fetching for the same account don't share one rate-limit budget.
 */
class ThrottlesMessageFetch
{
    public function __construct(private readonly string $accountId) {}

    public function handle(ReleasableJob $job, Closure $next): mixed
    {
        $key = "messages-fetch:{$this->accountId}";
        $max = max(1, (int) config('messages.fetch_rate_per_minute', 12));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return $job->release(RateLimiter::availableIn($key) + 1);
        }

        RateLimiter::hit($key, 60);

        return $next($job);
    }
}
