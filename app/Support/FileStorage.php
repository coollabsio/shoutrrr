<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

final class FileStorage
{
    public static function diskName(): string
    {
        return (string) config('filesystems.default');
    }

    public static function publicImageDiskName(): string
    {
        return (string) (config('filesystems.public_images') ?: self::diskName());
    }

    public static function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? self::diskName());
    }

    public static function url(string $path, ?string $disk = null): string
    {
        $disk ??= self::diskName();

        if (config("filesystems.disks.{$disk}.visibility") === 'public') {
            return Storage::disk($disk)->url($path);
        }

        return Storage::disk($disk)->temporaryUrl($path, now()->addHours(6));
    }
}
