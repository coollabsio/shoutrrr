<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

class AppVersion
{
    private static ?string $current = null;

    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $path = base_path('VERSION');

        if (! is_readable($path)) {
            Log::warning('VERSION file is missing or unreadable.', ['path' => $path]);

            return self::$current = '';
        }

        return self::$current = trim((string) file_get_contents($path));
    }

    /**
     * Pin the running version for tests so channel-dependent behaviour does not
     * couple to whatever the shipped VERSION file happens to contain. Passing
     * null clears the override and restores reading from the VERSION file.
     */
    public static function fake(?string $version): void
    {
        self::$current = $version;
    }

    public static function isOutdated(?string $latest): bool
    {
        if ($latest === null) {
            return false;
        }

        $current = ltrim(self::current(), 'v');
        $latest = ltrim($latest, 'v');

        if ($current === '' || $latest === '') {
            return false;
        }

        return version_compare($current, $latest, '<');
    }

    public static function isPrerelease(?string $version = null): bool
    {
        $version = ltrim($version ?? self::current(), 'v');

        return str_contains($version, '-');
    }
}
