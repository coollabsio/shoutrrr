<?php

declare(strict_types=1);

namespace App\Enums;

enum PostFormat: string
{
    case Feed = 'feed';
    case Reels = 'reels';
    case Story = 'story';

    public function label(): string
    {
        return match ($this) {
            self::Feed => 'Feed',
            self::Reels => 'Reels',
            self::Story => 'Stories',
        };
    }

    /**
     * Whether this format sends the post's text. Meta's Stories publishing
     * endpoints (IG media_type=STORIES, FB photo_stories/video_stories) accept
     * no caption/description field, so a story never carries text.
     */
    public function allowsCaption(): bool
    {
        return $this !== self::Story;
    }

    public function requiresVideo(): bool
    {
        return $this === self::Reels;
    }

    public function requiresMedia(): bool
    {
        return $this === self::Reels || $this === self::Story;
    }

    /**
     * Whether the format publishes a single media item (no carousel). Reels take
     * one video; a Story takes one image or video.
     */
    public function singleMediaOnly(): bool
    {
        return $this === self::Reels || $this === self::Story;
    }

    /**
     * The formats a platform offers. Only the Meta platforms (Instagram,
     * Facebook) have Reels/Stories; everything else is feed-only.
     *
     * @return list<self>
     */
    public static function forPlatform(Platform $platform): array
    {
        return match ($platform) {
            Platform::Instagram, Platform::Facebook => [self::Feed, self::Reels, self::Story],
            default => [self::Feed],
        };
    }
}
