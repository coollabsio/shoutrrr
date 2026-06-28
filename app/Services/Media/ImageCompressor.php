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

    public function compressToFit(string $bytes, int $maxBytes, string $mime): CompressionResult
    {
        if (strlen($bytes) <= $maxBytes) {
            return CompressionResult::untouched($bytes, $mime);
        }

        if ($mime === 'image/gif') {
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
