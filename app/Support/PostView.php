<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Posts\PostSplitter;

final class PostView
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Post $post): array
    {
        $splitter = app(PostSplitter::class);
        $mediaCount = $post->media->count();

        return [
            'id' => $post->id,
            'base_text' => $post->base_text,
            'status' => $post->status->value,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at->toIso8601String(),
            'destination' => self::destination($post),
            'targets' => $post->targets->map(fn (PostTarget $target): array => [
                'id' => $target->id,
                'connected_account_id' => $target->connected_account_id,
                'platform' => $target->platform->value,
                'handle' => $target->account?->handle,
                'display_name' => $target->account?->display_name,
                'avatar_url' => $target->account?->avatar_url,
                'sections' => $target->sections,
                'content_override' => $target->content_override,
                'auto_split' => $target->auto_split,
                'status' => $target->status->value,
                'error_kind' => $target->error_kind?->value,
                'error_message' => $target->error_message,
                'remote_id' => $target->remote_id,
                'issues' => $splitter->validateSections($target->sections, $target->platform, $mediaCount),
            ])->all(),
            'media' => $post->media->map(fn (PostMedia $media): array => [
                'id' => $media->id,
                'url' => $media->url(),
                'mime' => $media->mime,
                'kind' => $media->kind,
                'duration_seconds' => $media->duration_seconds,
                'alt_text' => $media->alt_text,
                'position' => $media->position,
            ])->all(),
        ];
    }

    /**
     * @return array{kind: string, id: string|null}
     */
    private static function destination(Post $post): array
    {
        if ($post->account_set_id !== null) {
            return ['kind' => 'set', 'id' => $post->account_set_id];
        }

        return $post->targets->count() === 1
            ? ['kind' => 'account', 'id' => $post->targets->first()->connected_account_id]
            : ['kind' => 'all', 'id' => null];
    }
}
