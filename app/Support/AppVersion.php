<?php

declare(strict_types=1);

namespace App\Support;

class AppVersion
{
    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ??= trim((string) @file_get_contents(base_path('VERSION')));
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
