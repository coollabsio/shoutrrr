<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ConnectedAccount;
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
        $defaultAccountId = $post->workspace()->value('default_connected_account_id');

        return [
            'id' => $post->id,
            'base_text' => $post->base_text,
            'mentions' => $post->mentions ?? [],
            'status' => $post->status->value,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at->toIso8601String(),
            'destination' => self::destination($post),
            'targets' => $post->targets
                ->sortByDesc(fn (PostTarget $target): bool => $target->connected_account_id === $defaultAccountId)
                ->map(fn (PostTarget $target): array => [
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
                    'issues' => $splitter->validateSections(
                        $target->sections,
                        $target->platform,
                        $mediaCount,
                        $target->account?->maxTextLength(),
                    ),
                ])->values()->all(),
            'media' => $post->media->map(fn (PostMedia $media): array => $media->toView())->values()->all(),
        ];
    }

    /**
     * @return array{kind: string, id: string|null, ids?: list<string>}
     */
    private static function destination(Post $post): array
    {
        if ($post->account_set_id !== null) {
            return ['kind' => 'set', 'id' => $post->account_set_id];
        }

        if ($post->targets->count() === 1) {
            return ['kind' => 'account', 'id' => $post->targets->first()->connected_account_id];
        }

        $targetIds = $post->targets
            ->pluck('connected_account_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->sort()
            ->all();
        $allAccountIds = ConnectedAccount::withoutGlobalScopes()
            ->where('workspace_id', $post->workspace_id)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->sort()
            ->all();

        $targetIds = array_values($targetIds);
        $allAccountIds = array_values($allAccountIds);

        return $targetIds === $allAccountIds
            ? ['kind' => 'all', 'id' => null]
            : ['kind' => 'accounts', 'id' => null, 'ids' => $targetIds];
    }
}
