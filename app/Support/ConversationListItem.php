<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Conversation;

final class ConversationListItem
{
    /** @return array<string, mixed> */
    public static function make(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'platform' => $conversation->platform->value,
            'counterpart_handle' => $conversation->counterpart_handle,
            'counterpart_name' => $conversation->counterpart_name,
            'counterpart_avatar_url' => $conversation->counterpart_avatar_url,
            'last_message_preview' => $conversation->last_message_preview,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'unread_count' => $conversation->unread_count,
            'is_archived' => $conversation->archived_at !== null,
            'can_reply' => $conversation->canReplyNow(),
            'window_expires_at' => $conversation->messaging_window_expires_at?->toIso8601String(),
            'account_handle' => $conversation->account?->handle,
        ];
    }
}
