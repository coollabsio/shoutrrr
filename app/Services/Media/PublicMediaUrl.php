<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Platform;
use App\Models\PostMedia;
use App\Support\FileStorage;
use Illuminate\Support\Facades\URL;

/**
 * Resolves a publicly reachable HTTPS URL for a stored media file, for
 * platforms (Instagram, Threads, TikTok) that publish by handing the platform
 * a URL it fetches server-side rather than accepting an uploaded byte stream.
 *
 * Two things make this different from the URL the browser loads (PostMedia::url):
 *
 *  - It must be absolute. The `public` disk is configured host-relative
 *    ('url' => '/storage') so an <img> resolves it against the current origin;
 *    Meta fetches from its own servers and has no origin to resolve against.
 *  - It must point at bytes in a format and size the platform accepts. The
 *    byte-upload connectors re-encode in flight, but these platforms read
 *    whatever is on disk, so a non-conforming image is converted to a derived
 *    JPEG first (downscaled when the platform caps image dimensions).
 */
class PublicMediaUrl
{
    public function __construct(private readonly ImageToJpegConverter $converter) {}

    /**
     * @param  Platform|null  $platform  The destination whose accepted image formats apply.
     *                                   Omitted, the stored file is used as-is.
     *
     * @throws ImageConversionFailed
     */
    public function for(PostMedia $media, ?Platform $platform = null): string
    {
        $disk = $media->disk;
        $path = $media->path;

        if ($platform !== null && ! $media->isVideo()) {
            $frame = $platform->fetchedImageFrame();

            if (! in_array($media->mime, $platform->allowedMime(), true) || $this->exceedsFrame($media, $frame)) {
                $converted = $this->converter->convert($media, $frame);
                $disk = $converted->disk;
                $path = $converted->path;
            }
        }

        return $this->absolute(FileStorage::url($path, $disk));
    }

    /**
     * Whether the image is larger than the platform's fetched-image frame. Unknown
     * dimensions count as too large, so the rendition is always safe to fetch.
     *
     * @param  array{long_edge: int, short_edge: int}|null  $frame
     */
    private function exceedsFrame(PostMedia $media, ?array $frame): bool
    {
        if ($frame === null) {
            return false;
        }

        if ($media->width === null || $media->height === null) {
            return true;
        }

        return max($media->width, $media->height) > $frame['long_edge']
            || min($media->width, $media->height) > $frame['short_edge'];
    }

    /**
     * Host-relative disk URLs are correct for the browser but unfetchable by Meta, so
     * resolve them against the app's own origin.
     */
    private function absolute(string $url): string
    {
        return parse_url($url, PHP_URL_SCHEME) === null ? URL::to($url) : $url;
    }
}
