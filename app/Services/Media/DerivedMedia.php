<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Platform;
use App\Models\PostMedia;

/**
 * The shared naming convention for files derived from a PostMedia at publish time,
 * when a platform needs the media in a format the stored original isn't in
 * (GifToMp4Converter, ImageToJpegConverter).
 *
 * Derived files sit beside the original on the same disk and are keyed by media id,
 * so a retry reuses the existing one and deleting the media can clean them up.
 */
final class DerivedMedia
{
    /** Extensions the converters write; kept here so deletion can find them all. */
    private const array EXTENSIONS = ['jpg', 'mp4'];

    /**
     * @param  string|null  $variant  Distinguishes renditions of the same format, e.g. a
     *                                downscaled JPEG (see frameVariant()).
     */
    public static function path(PostMedia $media, string $extension, ?string $variant = null): string
    {
        $suffix = $variant !== null ? '-'.$variant : '';

        return 'media/'.$media->workspace_id.'/derived/'.$media->id.$suffix.'.'.$extension;
    }

    /**
     * The variant name for a JPEG downscaled into a platform's fetched-image frame.
     *
     * @param  array{long_edge: int, short_edge: int}  $frame
     */
    public static function frameVariant(array $frame): string
    {
        return "fit{$frame['long_edge']}x{$frame['short_edge']}";
    }

    /**
     * Every path a converter could have written for this media.
     *
     * @return list<string>
     */
    public static function pathsFor(PostMedia $media): array
    {
        $paths = array_map(
            static fn (string $extension): string => self::path($media, $extension),
            self::EXTENSIONS,
        );

        foreach (Platform::cases() as $platform) {
            $frame = $platform->fetchedImageFrame();

            if ($frame !== null) {
                $paths[] = self::path($media, 'jpg', self::frameVariant($frame));
            }
        }

        return array_values(array_unique($paths));
    }
}
