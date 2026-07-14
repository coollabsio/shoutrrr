<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CommunityStats
{
    public const StarsCacheKey = 'community:stars';

    public const LatestVersionCacheKey = 'community:latest_version';

    public static function stars(): ?int
    {
        $value = Cache::get(self::StarsCacheKey);

        return is_int($value) ? $value : null;
    }

    public static function latestVersion(): ?string
    {
        $value = Cache::get(self::LatestVersionCacheKey);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function updateAvailable(): bool
    {
        return AppVersion::isOutdated(self::latestVersion());
    }
}
