<?php

declare(strict_types=1);

namespace App\Support;

class AppVersion
{
    public static function current(): string
    {
        return trim((string) @file_get_contents(base_path('VERSION')));
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
}
