<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Post;
use App\Models\PostTarget;

final class PostListItem
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Post $post): array
    {
        return [
            'id' => $post->id,
            'list_sort_at' => $post->getAttribute('list_sort_at'),
            'base_text' => $post->base_text,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'author' => $post->author?->name,
            'target_count' => $post->targets->count(),
            'updated_at' => $post->updated_at->toIso8601String(),
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'platforms' => $post->targets->pluck('platform')
                ->map(fn ($p): string => $p->value)->unique()->values()->all(),
            'targets' => $post->targets->map(fn (PostTarget $t): array => [
                'id' => $t->id,
                'platform' => $t->platform->value,
                'status' => $t->status->value,
                'error_kind' => $t->error_kind?->value,
                'error_message' => $t->error_message,
                'attempts' => $t->attempts,
            ])->all(),
        ];
    }
}
