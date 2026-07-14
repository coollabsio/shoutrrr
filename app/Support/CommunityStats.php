<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CommunityStats
{
    public const StarsCacheKey = 'community:stars';

    public const LatestStableCacheKey = 'community:latest_stable';

    public const LatestOverallCacheKey = 'community:latest_overall';

    public static function stars(): ?int
    {
        $value = Cache::get(self::StarsCacheKey);

        return is_int($value) ? $value : null;
    }

    /**
     * The newest release for the running channel: a prerelease install considers
     * prereleases and stable (overall); a stable install considers stable only.
     */
    public static function latestVersion(): ?string
    {
        return self::selectLatest(
            AppVersion::isPrerelease(),
            self::cachedTag(self::LatestStableCacheKey),
            self::cachedTag(self::LatestOverallCacheKey),
        );
    }

    public static function selectLatest(bool $currentIsPrerelease, ?string $stable, ?string $overall): ?string
    {
        return $currentIsPrerelease ? $overall : $stable;
    }

    public static function updateAvailable(): bool
    {
        return AppVersion::isOutdated(self::latestVersion());
    }

    private static function cachedTag(string $key): ?string
    {
        $value = Cache::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
