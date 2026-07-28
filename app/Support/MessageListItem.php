<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DirectMessage;

class MessageListItem
{
    /** @return array<string, mixed> */
    public static function make(DirectMessage $message): array
    {
        return [
            'id' => $message->id,
            'remote_message_id' => $message->remote_message_id,
            'direction' => $message->direction->value,
            'text' => $message->text,
            'attachments' => $message->attachments ?? [],
            'remote_created_at' => $message->remote_created_at?->toIso8601String(),
            'is_ours' => $message->is_ours,
            'send_status' => $message->send_status?->value,
        ];
    }
}
