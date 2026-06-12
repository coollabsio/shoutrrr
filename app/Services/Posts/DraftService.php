<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Dto\Post\DraftData;
use App\Enums\PostStatus;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class DraftService
{
    public function __construct(private readonly PostSplitter $splitter) {}

    /**
     * Create a draft and snapshot the destination's accounts into targets.
     *
     * @param  array{kind: string, id?: string|null}  $destination
     */
    public function createDraft(string $workspaceId, User $author, array $destination, string $baseText): Post
    {
        return DB::transaction(function () use ($workspaceId, $author, $destination, $baseText): Post {
            $post = Post::create([
                'workspace_id' => $workspaceId,
                'account_set_id' => ($destination['kind'] === 'set') ? ($destination['id'] ?? null) : null,
                'author_id' => $author->id,
                'base_text' => $baseText,
                'status' => PostStatus::Draft->value,
            ]);

            $accountIds = $this->resolveDestinationAccountIds($workspaceId, $destination);
            $this->syncTargets($post, $accountIds, $baseText, [], []);

            return $post->load('targets');
        });
    }

    /**
     * Resolve a destination descriptor to the concrete account ids it targets.
     *
     * @param  array{kind: string, id?: string|null}  $destination
     * @return list<string>
     */
    public function resolveDestinationAccountIds(string $workspaceId, array $destination): array
    {
        $ids = match ($destination['kind']) {
            'account' => isset($destination['id'])
                ? ConnectedAccount::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($destination['id'])
                    ->pluck('id')
                : collect(),
            'set' => isset($destination['id'])
                ? AccountSet::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($destination['id'])
                    ->first()?->accounts()->pluck('connected_accounts.id') ?? collect()
                : collect(),
            default => ConnectedAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->pluck('id'),
        };

        return array_values($ids->map(static fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * Smart-merge targets to exactly $accountIds: keep survivors (preserving their
     * per-account edits), drop removed accounts, seed new ones. Re-split every
     * surviving/new target from its effective text.
     *
     * @param  list<string>  $accountIds
     * @param  array<string, bool>  $autoSplitByAccount
     * @param  array<string, array{text?: string|null, media_ids?: list<string>}|null>  $overrideByAccount
     */
    public function syncTargets(Post $post, array $accountIds, string $baseText, array $autoSplitByAccount, array $overrideByAccount): void
    {
        $accounts = ConnectedAccount::withoutGlobalScopes()
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');

        $existing = $post->targets()->get()->keyBy('connected_account_id')->all();

        // Drop targets for accounts no longer in the destination.
        $post->targets()
            ->whereNotIn('connected_account_id', $accountIds)
            ->delete();

        foreach ($accountIds as $accountId) {
            $account = $accounts->get($accountId);
            if (! $account) {
                continue;
            }

            $current = $existing[$accountId] ?? null;
            $currentAutoSplit = $current instanceof PostTarget ? $current->auto_split : null;
            $currentOverride = $current instanceof PostTarget ? $current->content_override : null;

            $autoSplit = $autoSplitByAccount[$accountId] ?? $currentAutoSplit ?? true;
            $override = array_key_exists($accountId, $overrideByAccount)
                ? $overrideByAccount[$accountId]
                : $currentOverride;

            $effectiveText = $override['text'] ?? $baseText;
            $sections = $this->splitter->split($effectiveText, $account->platform, $autoSplit)->sections;

            PostTarget::updateOrCreate(
                ['post_id' => $post->id, 'connected_account_id' => $accountId],
                [
                    'platform' => $account->platform->value,
                    'sections' => $sections,
                    'content_override' => $override,
                    'auto_split' => $autoSplit,
                ],
            );
        }
    }

    /**
     * Update a draft: optimistic-concurrency check, destination smart-merge,
     * re-split all targets, attach + order media.
     *
     * @throws PostStaleWriteException
     */
    public function updateDraft(Post $post, DraftData $data): Post
    {
        return DB::transaction(function () use ($post, $data): Post {
            $post = Post::withoutGlobalScopes()->lockForUpdate()->findOrFail($post->id);

            if ($data->expectedUpdatedAt !== null
                && $post->updated_at->toIso8601String() !== Date::parse($data->expectedUpdatedAt)->toIso8601String()) {
                throw new PostStaleWriteException;
            }

            $destination = ['kind' => $data->destinationKind, 'id' => $data->destinationId];
            $accountIds = $this->resolveDestinationAccountIds($post->workspace_id, $destination);

            // Only carry an explicitly-sent override/auto-split into the merge;
            // otherwise syncTargets preserves the survivor's existing value.
            $autoSplitByAccount = [];
            $overrideByAccount = [];
            foreach ($accountIds as $accountId) {
                if ($data->hasAutoSplitFor($accountId)) {
                    $autoSplitByAccount[$accountId] = $data->autoSplitFor($accountId);
                }
                if ($data->hasOverrideFor($accountId)) {
                    $overrideByAccount[$accountId] = $data->overrideFor($accountId);
                }
            }

            $post->forceFill([
                'base_text' => $data->baseText,
                'account_set_id' => $data->destinationKind === 'set' ? $data->destinationId : null,
            ])->save();

            $this->syncTargets($post, $accountIds, $data->baseText, $autoSplitByAccount, $overrideByAccount);
            $this->attachMedia($post, $data->mediaIds);

            $post->touch();

            return $post->fresh(['targets', 'media']);
        });
    }

    /**
     * Attach the given media (in order) to the post and detach any others.
     *
     * @param  list<string>  $mediaIds
     */
    private function attachMedia(Post $post, array $mediaIds): void
    {
        // Detach media that are no longer referenced.
        PostMedia::withoutGlobalScopes()
            ->where('post_id', $post->id)
            ->whereNotIn('id', $mediaIds)
            ->update(['post_id' => null]);

        foreach ($mediaIds as $position => $mediaId) {
            PostMedia::withoutGlobalScopes()
                ->where('workspace_id', $post->workspace_id)
                ->whereKey($mediaId)
                ->update(['post_id' => $post->id, 'position' => $position]);
        }
    }
}
