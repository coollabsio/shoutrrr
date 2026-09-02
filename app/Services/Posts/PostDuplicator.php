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
use Throwable;

class PostDuplicator
{
    /**
     * Clone a post into a fresh draft: copy text, mentions, media (as new
     * files + rows) and per-account targets, resetting all publish state.
     * New draft targets rely on DB defaults for status/attempts/metrics
     * (status defaults to `pending`), so only content fields are carried.
     *
     * Backing files are copied *before* the DB transaction — a media disk is a
     * private S3 bucket in production, and a blocking network copy has no place
     * holding a DB connection/locks open. Any file written is tracked so a
     * failure anywhere rolls back the rows *and* deletes the orphaned copies.
     */
    public function duplicate(Post $source): Post
    {
        $source->loadMissing('media', 'targets');

        /** @var list<array{0: string, 1: string}> $copiedPaths */
        $copiedPaths = [];

        try {
            $mediaPlan = $this->copyMediaFiles($source, $copiedPaths);

            return DB::transaction(function () use ($source, $mediaPlan): Post {
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

                $mediaIdMap = $this->createMediaRows($draft, $mediaPlan);
                $this->cloneTargets($source, $draft, $mediaIdMap);

                return $draft->load('targets', 'media');
            });
        } catch (Throwable $e) {
            foreach ($copiedPaths as [$disk, $path]) {
                FileStorage::disk($disk)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Copy every media file + row from $source into an existing $target post.
     * Cleans up any copied storage files if a later step throws.
     */
    public function copyMediaInto(Post $target, Post $source): void
    {
        $source->loadMissing('media');

        /** @var list<array{0: string, 1: string}> $copiedPaths */
        $copiedPaths = [];

        try {
            $plan = $this->copyMediaFiles($source, $copiedPaths);
            $this->createMediaRows($target, $plan);
        } catch (Throwable $e) {
            foreach ($copiedPaths as [$disk, $path]) {
                FileStorage::disk($disk)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Copy each media file (and any retained pre-edit source) to a fresh path,
     * recording every written path in $copiedPaths for rollback cleanup.
     *
     * @param  list<array{0: string, 1: string}>  $copiedPaths
     * @return list<array{media: PostMedia, path: string, source_path: string|null}>
     */
    private function copyMediaFiles(Post $source, array &$copiedPaths): array
    {
        $plan = [];

        foreach ($source->media as $media) {
            $path = $this->copyFile($media->disk, $media->path);
            $copiedPaths[] = [$media->disk, $path];

            $sourcePath = null;
            if ($media->source_path !== null) {
                $sourceDisk = $media->source_disk ?? $media->disk;
                $sourcePath = $this->copyFile($sourceDisk, $media->source_path);
                $copiedPaths[] = [$sourceDisk, $sourcePath];
            }

            $plan[] = ['media' => $media, 'path' => $path, 'source_path' => $sourcePath];
        }

        return $plan;
    }

    /**
     * Create the draft's media rows from the pre-copied files.
     *
     * @param  list<array{media: PostMedia, path: string, source_path: string|null}>  $plan
     * @return array<string, string> old media id => new media id
     */
    private function createMediaRows(Post $draft, array $plan): array
    {
        $map = [];

        foreach ($plan as ['media' => $media, 'path' => $path, 'source_path' => $sourcePath]) {
            $copy = PostMedia::create([
                'workspace_id' => $draft->workspace_id,
                'post_id' => $draft->id,
                'disk' => $media->disk,
                'path' => $path,
                'mime' => $media->mime,
                'size_bytes' => $media->size_bytes,
                'width' => $media->width,
                'height' => $media->height,
                'alt_text' => $media->alt_text,
                'position' => $media->position,
                'kind' => $media->kind,
                'duration_seconds' => $media->duration_seconds,
                'source_disk' => $sourcePath === null ? null : ($media->source_disk ?? $media->disk),
                'source_path' => $sourcePath,
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
