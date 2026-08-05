<?php

declare(strict_types=1);

namespace App\Support;

class AppVersion
{
    private static ?string $current = null;

    /**
     * The running application version.
     *
     * Resolved from config('app.version') (set by the release pipeline from the
     * published git tag). Falls back to `git describe` in local development so
     * the version is meaningful without a committed VERSION file.
     */
    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $configured = trim((string) config('app.version'));

        if ($configured !== '') {
            return self::$current = $configured;
        }

        return self::$current = self::describeFromGit();
    }

    /**
     * Best-effort version from the local git checkout (dev only). Production
     * images have no `.git`, so this returns '' there and callers degrade
     * gracefully.
     */
    private static function describeFromGit(): string
    {
        if (! function_exists('exec')) {
            return '';
        }

        $command = sprintf(
            'git -C %s describe --tags --always 2>/dev/null',
            escapeshellarg(base_path()),
        );

        $output = @exec($command, $ignored, $status);

        return $status === 0 && is_string($output) ? trim($output) : '';
    }

    /**
     * Pin the running version for tests so channel-dependent behaviour does not
     * couple to whatever version the build happens to bake in. Passing null
     * clears the override and restores resolution from config/git.
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
