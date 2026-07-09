<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a publicly reachable HTTPS URL for a stored media file, for
 * platforms (Instagram, Threads) that publish by handing Meta a URL it
 * fetches server-side rather than accepting an uploaded byte stream.
 *
 * Mirrors PostMedia::resolveUrl(): a public-visibility disk already serves
 * plain URLs; a private disk (e.g. a private S3 bucket) needs a signed,
 * expiring URL, given a long TTL here since container processing can be slow.
 */
class PublicMediaUrl
{
    public function for(PostMedia $media): string
    {
        if (config("filesystems.disks.{$media->disk}.visibility") === 'public') {
            return Storage::disk($media->disk)->url($media->path);
        }

        return Storage::disk($media->disk)->temporaryUrl($media->path, now()->addHours(6));
    }
}
