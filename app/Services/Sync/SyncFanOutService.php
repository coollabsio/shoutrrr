<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\ConnectedAccountStatus;
use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostDuplicator;
use App\Services\Publishing\PublishDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncFanOutService
{
    public function __construct(
        private readonly PostDuplicator $duplicator,
        private readonly DraftService $drafts,
        private readonly PublishDispatcher $dispatcher,
    ) {}

    /**
     * Fan a just-published source target out to every enabled pipeline whose
     * source is that account. Idempotent per (source post, pipeline).
     */
    public function fanOut(PostTarget $sourceTarget): void
    {
        if (! config('sync.enabled')) {
            return;
        }

        $source = Post::withoutGlobalScopes()->find($sourceTarget->post_id);
        if ($source === null || $source->origin === PostOrigin::Sync || $source->skip_sync) {
            return;
        }

        $pipelines = SyncPipeline::withoutGlobalScopes()
            ->where('enabled', true)
            ->where('source_connected_account_id', $sourceTarget->connected_account_id)
            ->get();

        foreach ($pipelines as $pipeline) {
            $this->createSyncedPost($source, $pipeline);
        }
    }

    private function createSyncedPost(Post $source, SyncPipeline $pipeline): void
    {
        $alreadyTargeted = PostTarget::withoutGlobalScopes()
            ->where('post_id', $source->id)
            ->pluck('connected_account_id')
            ->all();

        $destinationIds = ConnectedAccount::withoutGlobalScopes()
            ->whereIn('id', $pipeline->destinations()->pluck('connected_accounts.id')->all())
            ->whereNotIn('id', $alreadyTargeted)
            ->enabled()
            ->where('status', ConnectedAccountStatus::Active->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($destinationIds === []) {
            return;
        }

        // Claim the (source, pipeline) pair first so the unique index guards the
        // race between the immediate event and the reconcile backstop.
        try {
            $synced = Post::create([
                'workspace_id' => $source->workspace_id,
                'author_id' => $source->author_id,
                'origin' => PostOrigin::Sync->value,
                'sync_pipeline_id' => $pipeline->id,
                'source_post_id' => $source->id,
                'segments' => $source->segments,
                'base_text' => $source->base_text,
                'mentions' => $source->mentions,
                'status' => PostStatus::Draft->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            return; // Another run already fanned this out.
        }

        try {
            DB::transaction(function () use ($synced, $source, $destinationIds): void {
                $this->duplicator->copyMediaInto($synced, $source);
                // No DraftData => no per-segment placements; all media rides the head
                // section (SegmentMediaResolver falls back to [0 => allMedia]).
                $this->drafts->syncTargets($synced, array_values($destinationIds), $source->segments ?? [], [], [], $source->mentions ?? [], []);
                $synced->forceFill(['status' => PostStatus::Publishing->value])->save();
            });
        } catch (Throwable $e) {
            $synced->delete(); // Let the backstop retry a clean fan-out.

            throw $e;
        }

        $this->dispatcher->dispatchForPost($synced->fresh(['targets', 'media']));
    }
}
