<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Community\GithubStatsFetcher;
use App\Support\CommunityStats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshCommunityStats extends Command
{
    protected $signature = 'community:refresh-stats';

    protected $description = 'Fetch the GitHub star count and newest release tags (stable + overall) for the sidebar community card.';

    public function handle(GithubStatsFetcher $fetcher): int
    {
        if (config('subscriptions.enabled')) {
            $this->info('Skipping community stats refresh on cloud.');

            return self::SUCCESS;
        }

        $stats = $fetcher->fetch();

        if ($stats['stars'] !== null) {
            Cache::put(CommunityStats::StarsCacheKey, $stats['stars'], now()->addDays(7));
        }

        if ($stats['latest_stable'] !== null) {
            Cache::put(CommunityStats::LatestStableCacheKey, $stats['latest_stable'], now()->addDays(7));
        }

        if ($stats['latest_overall'] !== null) {
            Cache::put(CommunityStats::LatestOverallCacheKey, $stats['latest_overall'], now()->addDays(7));
        }

        $this->info('Community stats refreshed.');

        return self::SUCCESS;
    }
}
