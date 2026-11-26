<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Re-encodes a stored image to JPEG alongside the original, for the URL-fetch platforms
 * (Instagram, Threads, TikTok) that reject the source format or, for TikTok, its size.
 *
 * Those platforms publish by handing the platform a public URL it fetches server-side, so
 * unlike the byte-upload connectors they cannot convert in flight — the bytes it reads are
 * whatever is on disk. The derived file is written next to the original (mirroring
 * GifToMp4Converter) and reused on a later publish attempt or retry.
 */
class ImageToJpegConverter
{
    private const int QUALITY = 90;

    /**
     * @param  int  $maxPixels  Decode guard mirroring ImageCompressor: refuse to decode a
     *                          pathologically large canvas rather than OOM the worker.
     */
    public function __construct(private readonly int $maxPixels = ImageCompressor::DEFAULT_MAX_PIXELS) {}

    /**
     * @param  array{long_edge: int, short_edge: int}|null  $frame  When given, the JPEG is
     *                                                              also scaled down (never up)
     *                                                              to fit this frame in the
     *                                                              image's own orientation.
     */
    public function convert(PostMedia $media, ?array $frame = null): ConvertedImage
    {
        $disk = Storage::disk($media->disk);
        $derivedPath = DerivedMedia::path($media, 'jpg', $frame !== null ? DerivedMedia::frameVariant($frame) : null);

        if ($disk->exists($derivedPath)) {
            return new ConvertedImage($media->disk, $derivedPath);
        }

        $bytes = $disk->get($media->path);

        if ($bytes === null || $bytes === '') {
            throw new ImageConversionFailed('Could not read the image to convert it to JPEG.');
        }

        $info = @getimagesizefromstring($bytes);

        if (is_array($info) && ($info[0] * $info[1]) > $this->maxPixels) {
            throw new ImageConversionFailed('The image resolution is too large to convert to JPEG.');
        }

        try {
            // JPEG has no alpha; the GD JPEG encoder flattens onto the driver's
            // configured background (white), so transparent PNGs keep a white
            // backdrop rather than picking up black fringing.
            $image = Image::fromBytes($bytes);

            if ($frame !== null) {
                [$width, $height] = $this->frameBounds($frame, is_array($info) ? $info[0] >= $info[1] : null);
                $image = $image->scale($width, $height);
            }

            $encoded = $image
                ->toJpg()
                ->quality(self::QUALITY)
                ->toBytes();
        } catch (Throwable $e) {
            throw new ImageConversionFailed('Could not convert the image to JPEG.', previous: $e);
        }

        $disk->put($derivedPath, $encoded);

        return new ConvertedImage($media->disk, $derivedPath);
    }

    /**
     * Width and height bounds for the frame in the image's orientation. When the
     * orientation is unknown, fit the short edge both ways so either one fits.
     *
     * @param  array{long_edge: int, short_edge: int}  $frame
     * @return array{int<1, max>, int<1, max>}
     */
    private function frameBounds(array $frame, ?bool $landscape): array
    {
        $long = max(1, $frame['long_edge']);
        $short = max(1, $frame['short_edge']);

        return match ($landscape) {
            true => [$long, $short],
            false => [$short, $long],
            null => [$short, $short],
        };
    }
}
