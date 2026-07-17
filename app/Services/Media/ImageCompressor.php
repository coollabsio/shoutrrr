<?php

declare(strict_types=1);

namespace App\Services\Media;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Re-encodes an image to fit a target byte limit AND a target platform's accepted mime
 * types, keeping as much quality as the budget allows: it picks the highest encoder
 * quality that fits before downscaling, and prefers WebP over JPEG when the target
 * platform accepts it (WebP is smaller at equal quality and preserves alpha). An image
 * already within the byte limit in an accepted mime is returned untouched; GIFs,
 * oversized-canvas images, and undecodable bytes are always returned untouched (the
 * connectors that hit those cases route GIFs through their own dedicated path instead).
 */
class ImageCompressor
{
    public const int DEFAULT_MAX_PIXELS = 50_000_000;

    private const int QUALITY_CEIL = 92;

    private const int QUALITY_FLOOR = 50;

    private const int QUALITY_STEP = 6;

    private const int DIMENSION_FLOOR = 640;

    private const float DOWNSCALE_FACTOR = 0.85;

    /**
     * @param  int  $maxPixels  Decode guard: images whose pixel count exceeds this are left
     *                          untouched rather than decoded, so a decompression-bomb (tiny
     *                          file, enormous canvas) cannot OOM the publish worker. The
     *                          default comfortably exceeds every platform's max dimensions.
     */
    public function __construct(private readonly int $maxPixels = self::DEFAULT_MAX_PIXELS) {}

    /**
     * @param  list<string>  $allowedMimes  The target platform's accepted image mime types,
     *                                      used to choose the output format (WebP when the
     *                                      platform allows it, otherwise JPEG).
     */
    public function compressToFit(string $bytes, int $maxBytes, string $mime, array $allowedMimes): CompressionResult
    {
        if (strlen($bytes) <= $maxBytes && in_array($mime, $allowedMimes, true)) {
            return CompressionResult::untouched($bytes, $mime);
        }

        if ($mime === 'image/gif') {
            return CompressionResult::untouched($bytes, $mime);
        }

        // Read dimensions from the header only (no canvas allocation) and refuse to decode
        // pathologically large images, guarding the worker against decompression bombs.
        $info = @getimagesizefromstring($bytes);
        if (is_array($info) && ($info[0] * $info[1]) > $this->maxPixels) {
            return CompressionResult::untouched($bytes, $mime);
        }

        try {
            $image = ImageManager::usingDriver(GdDriver::class)->decodeBinary($bytes);
        } catch (Throwable) {
            return CompressionResult::untouched($bytes, $mime);
        }

        // Prefer WebP where the platform accepts it: at a given byte budget it keeps
        // noticeably more quality than JPEG (and preserves alpha). Otherwise use JPEG.
        $useWebp = in_array('image/webp', $allowedMimes, true) && function_exists('imagewebp');
        $format = $useWebp ? Format::WEBP : Format::JPEG;
        $outMime = $useWebp ? 'image/webp' : 'image/jpeg';

        $longestEdge = max($image->width(), $image->height());

        while (true) {
            // Walk quality down from the ceiling and take the first (highest) encoding that
            // fits, so we preserve as much quality as the byte budget allows before resorting
            // to downscaling.
            for ($quality = self::QUALITY_CEIL; $quality >= self::QUALITY_FLOOR; $quality -= self::QUALITY_STEP) {
                $encoded = (string) $image->encodeUsingFormat($format, quality: $quality);

                if (strlen($encoded) <= $maxBytes) {
                    return CompressionResult::compressed($encoded, $outMime);
                }
            }

            $longestEdge = (int) floor($longestEdge * self::DOWNSCALE_FACTOR);

            if ($longestEdge < self::DIMENSION_FLOOR) {
                return CompressionResult::untouched($bytes, $mime);
            }

            $image->scaleDown($longestEdge, $longestEdge);
        }
    }
}
