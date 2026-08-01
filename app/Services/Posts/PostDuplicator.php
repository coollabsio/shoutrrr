<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Support\FileStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostDuplicator
{
    /**
     * Clone a post into a fresh draft: copy text, mentions, media (as new
     * files + rows) and per-account targets, resetting all publish state.
     * New draft targets rely on DB defaults for status/attempts/metrics
     * (status defaults to `pending`), so only content fields are carried.
     */
    public function duplicate(Post $source): Post
    {
        return DB::transaction(function () use ($source): Post {
            $source->loadMissing('media', 'targets');

            $draft = Post::create([
                'workspace_id' => $source->workspace_id,
                'account_set_id' => $source->account_set_id,
                'author_id' => $source->author_id,
                'segments' => $source->segments,
                'base_text' => $source->base_text,
                'mentions' => $source->mentions,
                'status' => PostStatus::Draft->value,
                'auto_repost' => $source->auto_repost,
            ]);

            $mediaIdMap = $this->cloneMedia($source, $draft);
            $this->cloneTargets($source, $draft, $mediaIdMap);

            return $draft->load('targets', 'media');
        });
    }

    /**
     * Copy every media row and its backing file(s) onto the draft.
     *
     * @return array<string, string> old media id => new media id
     */
    private function cloneMedia(Post $source, Post $draft): array
    {
        $map = [];

        foreach ($source->media as $media) {
            $newSourcePath = $media->source_path === null
                ? null
                : $this->copyFile($media->source_disk ?? $media->disk, $media->source_path);

            $copy = PostMedia::create([
                'workspace_id' => $draft->workspace_id,
                'post_id' => $draft->id,
                'disk' => $media->disk,
                'path' => $this->copyFile($media->disk, $media->path),
                'mime' => $media->mime,
                'size_bytes' => $media->size_bytes,
                'width' => $media->width,
                'height' => $media->height,
                'alt_text' => $media->alt_text,
                'position' => $media->position,
                'kind' => $media->kind,
                'duration_seconds' => $media->duration_seconds,
                'source_disk' => $newSourcePath === null ? null : ($media->source_disk ?? $media->disk),
                'source_path' => $newSourcePath,
                'edit_settings' => $media->edit_settings,
            ]);

            $map[$media->id] = $copy->id;
        }

        return $map;
    }

    private function copyFile(string $disk, string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $newPath = 'media/'.Str::uuid()->toString().($extension === '' ? '' : '.'.$extension);

        FileStorage::disk($disk)->copy($path, $newPath);

        return $newPath;
    }

    /**
     * Recreate one target per still-existing account; skip accounts that were
     * hard-deleted. Publish/metric fields fall back to their DB defaults.
     *
     * @param  array<string, string>  $mediaIdMap
     */
    private function cloneTargets(Post $source, Post $draft, array $mediaIdMap): void
    {
        $existingAccountIds = ConnectedAccount::withoutGlobalScopes()
            ->where('workspace_id', $source->workspace_id)
            ->whereIn('id', $source->targets->pluck('connected_account_id'))
            ->pluck('id')
            ->all();

        foreach ($source->targets as $target) {
            if (! in_array($target->connected_account_id, $existingAccountIds, true)) {
                continue;
            }

            PostTarget::create([
                'post_id' => $draft->id,
                'connected_account_id' => $target->connected_account_id,
                'platform' => $target->platform->value,
                'sections' => $target->sections,
                'content_override' => $this->remapOverride($target->content_override, $mediaIdMap),
                'auto_split' => $target->auto_split,
                'format' => $target->format->value,
            ]);
        }
    }

    /**
     * Point an override's `media_ids` at the cloned media rows.
     *
     * @param  array{text?: string|null, media_ids?: list<string>}|null  $override
     * @param  array<string, string>  $mediaIdMap
     * @return array{text?: string|null, media_ids?: list<string>}|null
     */
    private function remapOverride(?array $override, array $mediaIdMap): ?array
    {
        if ($override === null || ! isset($override['media_ids'])) {
            return $override;
        }

        $override['media_ids'] = array_map(
            static fn (string $id): string => $mediaIdMap[$id] ?? $id,
            $override['media_ids'],
        );

        return $override;
    }
}
