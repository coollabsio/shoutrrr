<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PostTargetReply;

final class ReplyListItem
{
    /**
     * @return array<string, mixed>
     */
    public static function make(PostTargetReply $reply): array
    {
        $target = $reply->target;

        return [
            'id' => $reply->id,
            'platform' => $reply->platform->value,
            'remote_reply_id' => $reply->remote_reply_id,
            'author_handle' => $reply->author_handle,
            'author_name' => $reply->author_name,
            'author_avatar_url' => $reply->author_avatar_url,
            'text' => $reply->text,
            'remote_created_at' => $reply->remote_created_at->toIso8601String(),
            'is_read' => $reply->read_at !== null,
            'is_liked' => $reply->liked_at !== null,
            'is_ours' => $reply->is_ours,
            'send_status' => $reply->send_status?->value,
            'status' => $reply->status->value,
            'post_target_id' => $reply->post_target_id,
            'post_id' => $target?->post_id,
            'post_excerpt' => $target?->post?->excerpt(),
            'account_handle' => $target?->account?->handle,
            'account_max_text_length' => $target?->account?->maxTextLength(),
        ];
    }
}
