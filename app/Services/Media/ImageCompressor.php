<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Image;
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

        // Read dimensions from the header only (no canvas allocation): undecodable bytes and
        // pathologically large canvases are refused before any decode, guarding the worker
        // against decompression bombs.
        $info = @getimagesizefromstring($bytes);

        if (! is_array($info) || ($info[0] * $info[1]) > $this->maxPixels) {
            return CompressionResult::untouched($bytes, $mime);
        }

        // Prefer WebP where the platform accepts it: at a given byte budget it keeps
        // noticeably more quality than JPEG (and preserves alpha). The encode attempt
        // (below) falls back to JPEG if the active image driver cannot produce WebP, so
        // this is a preference, not a hard requirement on any one driver.
        $preferWebp = in_array('image/webp', $allowedMimes, true);
        $outMime = 'image/jpeg';

        $longestEdge = max(1, $info[0], $info[1]);

        while (true) {
            // Walk quality down from the ceiling and take the first (highest) encoding that
            // fits, so we preserve as much quality as the byte budget allows before resorting
            // to downscaling. The immutable pipeline re-encodes from the source bytes each
            // pass; publishing runs in a queued job and the iteration count is bounded.
            for ($quality = self::QUALITY_CEIL; $quality >= self::QUALITY_FLOOR; $quality -= self::QUALITY_STEP) {
                $encoded = $this->encode($bytes, max(1, $longestEdge), $preferWebp, $quality, $outMime);

                if ($encoded === null) {
                    return CompressionResult::untouched($bytes, $mime);
                }

                if (strlen($encoded) <= $maxBytes) {
                    return CompressionResult::compressed($encoded, $outMime);
                }
            }

            $longestEdge = (int) floor($longestEdge * self::DOWNSCALE_FACTOR);

            if ($longestEdge < self::DIMENSION_FLOOR) {
                return CompressionResult::untouched($bytes, $mime);
            }
        }
    }

    /**
     * Encode the source bytes at the given longest edge and quality, preferring WebP where
     * the platform accepts it and falling back to JPEG when the active driver cannot encode
     * WebP (so a GD build without WebP, or the Imagick driver, still degrades to a valid
     * accepted format rather than shipping the uncompressed original).
     *
     * @param  int<1, max>  $edge  Longest-edge cap passed to scale() (never upsizes).
     * @param  int<1, 100>  $quality  Encoder quality.
     * @param  string  $outMime  Set by reference to the mime of the format actually encoded.
     * @return string|null The encoded bytes, or null if neither format could be encoded.
     */
    private function encode(string $bytes, int $edge, bool $preferWebp, int $quality, ?string &$outMime = null): ?string
    {
        if ($preferWebp) {
            try {
                $encoded = (string) Image::fromBytes($bytes)->scale($edge, $edge)->toWebp()->quality($quality)->toBytes();
                $outMime = 'image/webp';

                return $encoded;
            } catch (Throwable) {
                // WebP unsupported on the active driver/build — fall through to JPEG.
            }
        }

        try {
            $encoded = (string) Image::fromBytes($bytes)->scale($edge, $edge)->toJpg()->quality($quality)->toBytes();
            $outMime = 'image/jpeg';

            return $encoded;
        } catch (Throwable) {
            return null;
        }
    }
}
