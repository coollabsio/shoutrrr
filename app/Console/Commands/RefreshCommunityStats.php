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

    protected $description = 'Fetch the GitHub star count and latest release tag for the sidebar community card.';

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

        if ($stats['latest_version'] !== null) {
            Cache::put(CommunityStats::LatestVersionCacheKey, $stats['latest_version'], now()->addDays(7));
        }

        $this->info('Community stats refreshed.');

        return self::SUCCESS;
    }
}
