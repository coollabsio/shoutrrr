<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DirectMessage;
use App\Models\PostMedia;

final class MessageListItem
{
    /** @return array<string, mixed> */
    public static function make(DirectMessage $message): array
    {
        return [
            'id' => $message->id,
            'remote_message_id' => $message->remote_message_id,
            'direction' => $message->direction->value,
            'text' => $message->text,
            'attachments' => self::attachments($message),
            'remote_created_at' => $message->remote_created_at?->toIso8601String(),
            'is_ours' => $message->is_ours,
            'send_status' => $message->send_status?->value,
        ];
    }

    /**
     * Attachments we delivered are resolved from the media rows every time
     * rather than frozen into the message: a video's URL is a time-limited
     * signed URL, so a stored copy would stop loading within hours.
     *
     * The `attachments` column is the fallback for whatever the fetch
     * connectors eventually parse off the platform for inbound messages.
     *
     * @return list<array<string, mixed>>
     */
    private static function attachments(DirectMessage $message): array
    {
        $ours = array_values($message->media
            ->map(fn (PostMedia $media): array => [
                'kind' => $media->kind,
                'url' => $media->url(),
                'mime' => $media->mime,
                'alt_text' => $media->alt_text,
            ])
            ->all());

        if ($ours !== []) {
            return $ours;
        }

        /** @var list<array<string, mixed>> $stored */
        $stored = array_values(array_filter($message->attachments ?? [], is_array(...)));

        return $stored;
    }
}
