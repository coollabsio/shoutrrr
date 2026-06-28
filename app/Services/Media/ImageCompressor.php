<?php

declare(strict_types=1);

namespace App\Services\Media;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Re-encodes an oversized image to JPEG so it fits a target byte limit.
 * Images already within the limit (or GIFs, or undecodable bytes) are
 * returned untouched.
 */
class ImageCompressor
{
    private const int QUALITY_START = 82;

    private const int QUALITY_FLOOR = 40;

    private const int QUALITY_STEP = 7;

    private const int DIMENSION_FLOOR = 640;

    private const float DOWNSCALE_FACTOR = 0.85;

    /**
     * @param  int  $maxPixels  Decode guard: images whose pixel count exceeds this are left
     *                          untouched rather than decoded, so a decompression-bomb (tiny
     *                          file, enormous canvas) cannot OOM the publish worker. The
     *                          default comfortably exceeds every platform's max dimensions.
     */
    public function __construct(private readonly int $maxPixels = 50_000_000) {}

    public function compressToFit(string $bytes, int $maxBytes, string $mime): CompressionResult
    {
        if (strlen($bytes) <= $maxBytes) {
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

        $longestEdge = max($image->width(), $image->height());

        while (true) {
            for ($quality = self::QUALITY_START; $quality >= self::QUALITY_FLOOR; $quality -= self::QUALITY_STEP) {
                $encoded = (string) $image->encodeUsingFormat(Format::JPEG, quality: $quality);

                if (strlen($encoded) <= $maxBytes) {
                    return CompressionResult::compressed($encoded);
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
