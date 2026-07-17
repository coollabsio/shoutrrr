<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Re-encodes a stored image to JPEG alongside the original, for the URL-fetch platforms
 * (Instagram, Threads) that reject the source format.
 *
 * Those platforms publish by handing Meta a public URL it fetches server-side, so unlike
 * the byte-upload connectors they cannot convert in flight — the bytes Meta reads are
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

    public function convert(PostMedia $media): ConvertedImage
    {
        $disk = Storage::disk($media->disk);
        $derivedPath = $this->derivedPath($media);

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
            $encoded = Image::fromBytes($bytes)
                ->toJpg()
                ->quality(self::QUALITY)
                ->toBytes();
        } catch (Throwable $e) {
            throw new ImageConversionFailed('Could not convert the image to JPEG.', previous: $e);
        }

        $disk->put($derivedPath, $encoded);

        return new ConvertedImage($media->disk, $derivedPath);
    }

    private function derivedPath(PostMedia $media): string
    {
        return DerivedMedia::path($media, 'jpg');
    }
}
