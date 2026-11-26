<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a TikTok post is delivered. Direct Post publishes straight to the
 * creator's profile (`video.publish`); Inbox sends the media to the creator's
 * TikTok inbox as a draft they finish and post in the TikTok app
 * (`video.upload`).
 */
enum TikTokPostMode: string
{
    case Direct = 'direct';
    case Inbox = 'inbox';

    /**
     * The `post_mode` value of TikTok's content (photo) init endpoint.
     */
    public function photoPostMode(): string
    {
        return match ($this) {
            self::Direct => 'DIRECT_POST',
            self::Inbox => 'MEDIA_UPLOAD',
        };
    }
}
