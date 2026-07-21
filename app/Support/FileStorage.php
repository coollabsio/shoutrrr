<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class FileStorage
{
    public static function diskName(): string
    {
        return (string) config('filesystems.default');
    }

    /**
     * Presigned upload target for a video. Object-storage disks return a native
     * direct-to-storage PUT (the app never touches the bytes). A local disk
     * returns a relative signed URL to the streaming upload route, which copies
     * the body to disk without buffering it in memory — see StreamedUploadController.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public static function temporaryVideoUploadUrl(string $key, DateTimeInterface $expiration): array
    {
        $disk = self::diskName();

        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            return [
                'url' => URL::temporarySignedRoute('uploads.stream', $expiration, ['path' => $key], absolute: false),
                'headers' => [],
            ];
        }

        /** @var array{url: string, headers: array<string, string>} $presigned */
        $presigned = Storage::disk($disk)->temporaryUploadUrl($key, $expiration);

        return $presigned;
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
