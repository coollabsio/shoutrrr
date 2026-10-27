<?php

declare(strict_types=1);

namespace App\Services\NativeRead;

use App\Dto\NativeRead\NativeMedia;
use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Events\PostTargetPublished;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Models\Workspace;
use App\Services\Posts\MediaStorageService;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExternalIngestService
{
    /**
     * Media download budget per ingest run, kept well under the job's 900s timeout
     * (a single video may still take its own 300s): a run killed mid-post would
     * retry the same post forever and wedge the account.
     */
    private const int MEDIA_BUDGET_SECONDS = 480;

    private int $mediaDeadline = 0;

    public function __construct(
        private readonly NativeReadConnectorRegistry $registry,
        private readonly TokenManager $tokens,
        private readonly MediaStorageService $media,
    ) {}

    public function ingest(ConnectedAccount $account): void
    {
        if (! config('sync.enabled') || ! $account->platform->supportsNativeRead()) {
            return;
        }

        $watch = $account->nativeWatch()->first();
        if ($watch === null) {
            return;
        }

        $this->mediaDeadline = now()->getTimestamp() + self::MEDIA_BUDGET_SECONDS;
        $cursor = new NativeReadCursor($watch->enabled_at, $watch->last_seen_remote_id);
        $credentials = $this->tokens->fresh($account);
        $result = $this->registry->for($account->platform)->fetchRecent($account, $cursor, $credentials);

        if (! $result->isOk()) {
            $watch->forceFill(['last_polled_at' => now()])->save();

            return;
        }

        $isSource = SyncPipeline::withoutGlobalScopes()
            ->where('enabled', true)
            ->where('source_connected_account_id', $account->id)
            ->exists();

        // Oldest-first so chronological ingest + a monotonic cursor advance.
        foreach (array_reverse($result->posts) as $native) {
            if ($native->isReply || $native->isRepost || $native->createdAt < $watch->enabled_at) {
                continue;
            }
            // Anti-loop + dedup: never ingest a remote_id we already hold for this account.
            $seen = PostTarget::withoutGlobalScopes()
                ->where('connected_account_id', $account->id)
                ->where('remote_id', $native->remoteId)
                ->exists();
            if ($seen) {
                continue;
            }

            $this->createExternalPost($account, $native, $isSource);
        }

        $watch->forceFill([
            'last_seen_remote_id' => $result->newestRemoteId ?? $watch->last_seen_remote_id,
            'last_polled_at' => now(),
        ])->save();
    }

    private function createExternalPost(ConnectedAccount $account, NativePost $native, bool $downloadMedia): void
    {
        // Full media ingest only for pipeline sources (so fan-out can re-upload).
        // Download before the transaction: a large video must not hold a write lock.
        $stored = $downloadMedia ? $this->downloadMedia($account, $native) : [];

        $target = DB::transaction(function () use ($account, $native, $stored): PostTarget {
            $post = Post::create([
                'workspace_id' => $account->workspace_id,
                'author_id' => $account->connected_by_user_id
                    ?? Workspace::whereKey($account->workspace_id)->value('owner_id'),
                'origin' => PostOrigin::External->value,
                'segments' => [$native->text],
                'base_text' => $native->text,
                'status' => PostStatus::Published->value,
                'published_at' => $native->createdAt,
                'external_media' => array_map(
                    static fn (NativeMedia $m): array => ['url' => $m->url, 'kind' => $m->kind],
                    $native->media,
                ),
            ]);

            foreach ($stored as $position => $media) {
                $media->forceFill(['post_id' => $post->id, 'position' => $position])->save();
            }

            return PostTarget::create([
                'post_id' => $post->id,
                'connected_account_id' => $account->id,
                'platform' => $account->platform->value,
                'sections' => [$native->text],
                'status' => PostTargetStatus::Published->value,
                'remote_id' => $native->remoteId,
                'remote_ids' => [$native->remoteId],
                'posted_at' => $native->createdAt,
            ]);
        });

        // Reuse the Phase 1 fan-out path: fans out iff this account is a pipeline source.
        PostTargetPublished::dispatch($target);
    }

    /**
     * Download each native image/video as an orphan PostMedia row. A failed item
     * is skipped (orphans left by a later failure are pruned by PruneAbandonedUploads).
     *
     * @return list<PostMedia>
     */
    private function downloadMedia(ConnectedAccount $account, NativePost $native): array
    {
        $stored = [];
        foreach ($native->media as $m) {
            if (now()->getTimestamp() >= $this->mediaDeadline) {
                Log::warning('native media download skipped: run budget spent', ['account' => $account->id, 'url' => $m->url]);

                continue;
            }
            try {
                $stored[] = match ($m->kind) {
                    'image' => $this->media->storeFromUrl($account->workspace_id, $m->url),
                    'video' => $this->media->storeVideoFromUrl($account->workspace_id, $m->url),
                    default => throw new RuntimeException('Unsupported media kind '.$m->kind),
                };
            } catch (Throwable $e) {
                // A single bad item (oversized/dead/blocked-host URL) must not
                // wedge the whole account: skip it and keep the post + cursor advancing.
                Log::warning('native media download failed', [
                    'account' => $account->id,
                    'url' => $m->url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stored;
    }
}
