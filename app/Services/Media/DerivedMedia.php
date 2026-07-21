<?php

declare(strict_types=1);

namespace App\Services\Media;

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

    public static function path(PostMedia $media, string $extension): string
    {
        return 'media/'.$media->workspace_id.'/derived/'.$media->id.'.'.$extension;
    }

    /**
     * Every path a converter could have written for this media.
     *
     * @return list<string>
     */
    public static function pathsFor(PostMedia $media): array
    {
        return array_map(
            static fn (string $extension): string => self::path($media, $extension),
            self::EXTENSIONS,
        );
    }
}
