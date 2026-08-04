<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Dto\Post\DraftData;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Support\InstanceSettings;
use App\Support\LinkedInOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class DraftService
{
    /**
     * Handle-map key holding a mention's LinkedIn organization URN. Not a real
     * platform — it augments the `linkedin` display handle to produce a tag.
     */
    private const string LINKEDIN_URN_KEY = 'linkedin_urn';

    public function __construct(private readonly PostSplitter $splitter) {}

    /**
     * Create a draft and snapshot the destination's accounts into targets.
     *
     * @param  array{kind: string, id?: string|null, ids?: list<string>}  $destination
     * @param  list<string>  $segments
     * @param  list<array{id?: mixed, label?: mixed, handles?: array<string, mixed>}>  $mentions
     */
    public function createDraft(string $workspaceId, User $author, array $destination, array $segments, array $mentions = [], ?bool $autoRepost = null, ?DraftData $data = null): Post
    {
        return DB::transaction(function () use ($workspaceId, $author, $destination, $segments, $mentions, $autoRepost, $data): Post {
            $post = Post::create([
                'workspace_id' => $workspaceId,
                'account_set_id' => $this->scopedAccountSetId($workspaceId, $destination),
                'author_id' => $author->id,
                'segments' => $segments,
                'base_text' => implode("\n", $segments),
                'mentions' => $this->normalizeMentions($mentions),
                'status' => PostStatus::Draft->value,
                'auto_repost' => $autoRepost,
            ]);

            $accountIds = $this->resolveDestinationAccountIds($workspaceId, $destination);
            // Pass the DraftData so the created targets carry the thread's
            // segment_breaks from the first save (placements settle on the next
            // PUT once their media is attached).
            $this->syncTargets($post, $accountIds, $segments, [], [], $post->mentions ?? [], [], $data);

            return $post->load('targets');
        });
    }

    /**
     * Resolve a destination descriptor to the concrete account ids it targets.
     *
     * @param  array{kind: string, id?: string|null, ids?: list<string>}  $destination
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
            'accounts' => ConnectedAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $destination['ids'] ?? [])
                ->pluck('id'),
            default => ConnectedAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->pluck('id'),
        };

        $frozen = $this->frozenPlatformValues();

        $ids = ConnectedAccount::withoutGlobalScopes()
            ->whereKey($ids->all())
            ->enabled()
            ->when($frozen !== [], fn (Builder $query): Builder => $query->whereNotIn('platform', $frozen))
            ->pluck('id');

        return $this->defaultFirst($workspaceId, $ids->map(static fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * The platform values that are frozen instance-wide, so draft targeting
     * never snapshots an account whose platform is disabled.
     *
     * @return list<string>
     */
    private function frozenPlatformValues(): array
    {
        return array_keys(array_filter(
            app(InstanceSettings::class)->platformsEnabled(),
            static fn (bool $enabled): bool => ! $enabled,
        ));
    }

    /**
     * @param  array<int, string>  $accountIds
     * @return list<string>
     */
    private function defaultFirst(string $workspaceId, array $accountIds): array
    {
        $defaultAccountId = (string) (DB::table((new Workspace)->getTable())
            ->where('id', $workspaceId)
            ->value('default_connected_account_id') ?? '');

        if ($defaultAccountId === '') {
            return array_values($accountIds);
        }

        usort(
            $accountIds,
            static fn (string $left, string $right): int => (int) ($right === $defaultAccountId) <=> (int) ($left === $defaultAccountId),
        );

        return $accountIds;
    }

    /**
     * Smart-merge targets to exactly $accountIds: keep survivors (preserving their
     * per-account edits), drop removed accounts, seed new ones. Re-split every
     * surviving/new target from its effective text.
     *
     * @param  list<string>  $accountIds
     * @param  list<string>  $segments
     * @param  array<string, bool>  $autoSplitByAccount
     * @param  array<string, array{segments: list<string>, media_ids: list<string>}|null>  $overrideByAccount
     * @param  list<array{id: string, label: string, handles: array<string, string>}>  $mentions
     * @param  array<string, string>  $formatByAccount
     */
    public function syncTargets(Post $post, array $accountIds, array $segments, array $autoSplitByAccount, array $overrideByAccount, array $mentions = [], array $formatByAccount = [], ?DraftData $data = null): void
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

            $currentFormat = $current instanceof PostTarget ? $current->format->value : null;
            $format = $formatByAccount[$accountId] ?? $currentFormat ?? 'feed';

            $effectiveSegments = $override['segments'] ?? $segments;
            $resolvedSegments = array_map(
                fn (string $segment): string => $this->resolveMentionTokens($segment, $mentions, $account->platform->value),
                $effectiveSegments,
            );

            // A partial update (e.g. an MCP text-only edit) omits placements /
            // segment_breaks entirely. Treating that omission as an explicit empty
            // would delete every placement and flatten the thread structure, so
            // fall back to the target's stored state when the payload is silent.
            $breaksProvided = $data instanceof DraftData && $data->hasSegmentBreaksFor($accountId);
            $placementsProvided = $data instanceof DraftData && $data->hasPlacementsFor($accountId);

            $breaks = $breaksProvided
                ? $data->segmentBreaksFor($accountId)
                : ($current instanceof PostTarget ? ($current->segment_breaks ?? []) : []);
            $placements = $placementsProvided
                ? $data->placementsFor($accountId)
                : ($current instanceof PostTarget ? $this->existingPlacements($current) : []);
            $mediaSegments = $this->mediaSegmentsFromPlacements($placements, $breaks);

            $split = $this->splitter->split(
                $resolvedSegments,
                $account->platform,
                $autoSplit,
                $account->maxTextLength(),
                $mediaSegments,
            );

            $target = PostTarget::updateOrCreate(
                ['post_id' => $post->id, 'connected_account_id' => $accountId],
                [
                    'platform' => $account->platform->value,
                    'sections' => $split->sections,
                    'segment_breaks' => $breaks,
                    'section_sources' => $split->sectionSources,
                    'content_override' => $override,
                    'auto_split' => $autoSplit,
                    'format' => $format,
                ],
            );

            // Only rewrite placements when the caller actually sent them; a partial
            // update leaves the target's existing placement rows in place.
            if ($placementsProvided) {
                $this->syncPlacements($post, $target, $placements);
            }
        }
    }

    /**
     * Read a target's stored placements back into the payload shape so a partial
     * update can preserve (and re-derive media sections from) them unchanged.
     *
     * @return list<array{media_id: string, segment_ref: string, position: int}>
     */
    private function existingPlacements(PostTarget $target): array
    {
        return array_values($target->placements()->get()
            ->map(static fn (PostMediaPlacement $placement): array => [
                'media_id' => $placement->post_media_id,
                'segment_ref' => $placement->segment_ref,
                'position' => $placement->position,
            ])
            ->all());
    }

    /**
     * Map each placement's segment_ref to the authored-segment index it targets
     * (`'__head__'` is index 0; otherwise the ref's position in `$breaks` + 1),
     * so media-only segments still yield a section from the splitter. Refs that
     * don't match a known break are skipped.
     *
     * @param  list<array{media_id: string, segment_ref: string, position: int}>  $placements
     * @param  list<string>  $breaks
     * @return list<int>
     */
    private function mediaSegmentsFromPlacements(array $placements, array $breaks): array
    {
        $indices = [];

        foreach ($placements as $placement) {
            $ref = $placement['segment_ref'];
            if ($ref === '__head__') {
                $indices[] = 0;

                continue;
            }

            $position = array_search($ref, $breaks, true);
            if ($position === false) {
                continue;
            }

            $indices[] = $position + 1;
        }

        return array_values(array_unique($indices));
    }

    /**
     * Delete-and-reinsert this target's media placements, de-duped on
     * `post_media_id` (the table's unique key) and guarded so a placement can
     * never FK-violate on media that isn't (or is no longer) attached to the post.
     *
     * @param  list<array{media_id: string, segment_ref: string, position: int}>  $placements
     */
    private function syncPlacements(Post $post, PostTarget $target, array $placements): void
    {
        $target->placements()->delete();

        if ($placements === []) {
            return;
        }

        $attachedMediaIds = PostMedia::withoutGlobalScopes()
            ->where('post_id', $post->id)
            ->pluck('id')
            ->all();
        $attachedMediaIds = array_flip($attachedMediaIds);

        $seen = [];
        foreach ($placements as $placement) {
            $mediaId = $placement['media_id'];
            if (! isset($attachedMediaIds[$mediaId]) || isset($seen[$mediaId])) {
                continue;
            }
            $seen[$mediaId] = true;

            PostMediaPlacement::create([
                'post_target_id' => $target->id,
                'post_media_id' => $mediaId,
                'segment_ref' => $placement['segment_ref'],
                'position' => $placement['position'],
            ]);
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

            $destination = [
                'kind' => $data->destinationKind,
                'id' => $data->destinationId,
                'ids' => $data->destinationIds,
            ];
            $accountIds = $this->resolveDestinationAccountIds($post->workspace_id, $destination);

            // Only carry an explicitly-sent override/auto-split into the merge;
            // otherwise syncTargets preserves the survivor's existing value.
            $autoSplitByAccount = [];
            $overrideByAccount = [];
            $formatByAccount = [];
            foreach ($accountIds as $accountId) {
                if ($data->hasAutoSplitFor($accountId)) {
                    $autoSplitByAccount[$accountId] = $data->autoSplitFor($accountId);
                }
                if ($data->hasOverrideFor($accountId)) {
                    $overrideByAccount[$accountId] = $data->overrideFor($accountId);
                }
                if ($data->hasFormatFor($accountId)) {
                    $formatByAccount[$accountId] = $data->formatFor($accountId);
                }
            }

            $attributes = [
                'segments' => $data->segments,
                'base_text' => implode("\n", $data->segments),
                'mentions' => $this->normalizeMentions($data->mentions),
                'account_set_id' => $this->scopedAccountSetId($post->workspace_id, $destination),
            ];
            // Only overwrite the per-post boost override when the caller sent it;
            // a partial update that omits `auto_repost` must not reset it to null.
            if ($data->autoRepostProvided) {
                $attributes['auto_repost'] = $data->autoRepost;
            }

            $post->forceFill($attributes)->save();

            // Attach media BEFORE syncing targets: placement rows FK to post_media
            // rows scoped to this post, and attachMedia is what sets post_id on
            // them — syncing targets first would leave placements referencing
            // still-orphaned media. Only when the caller actually sent media_ids;
            // a partial update (e.g. an MCP text-only edit) that omits the key
            // must not detach every media row already on the post.
            if ($data->mediaIdsProvided) {
                $this->attachMedia($post, $data->mediaIds);
            }
            $this->syncTargets($post, $accountIds, $data->segments, $autoSplitByAccount, $overrideByAccount, $post->mentions ?? [], $formatByAccount, $data);

            $post->touch();

            return $post->fresh(['targets', 'media']);
        });
    }

    /**
     * @param  list<array{id?: mixed, label?: mixed, handles?: array<string, mixed>}>  $mentions
     * @return list<array{id: string, label: string, handles: array<string, string>}>
     */
    private function normalizeMentions(array $mentions): array
    {
        $normalized = [];

        foreach ($mentions as $mention) {
            $id = trim((string) ($mention['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $handles = [];
            foreach (($mention['handles'] ?? []) as $platform => $handle) {
                $platform = (string) $platform;
                $handle = trim((string) $handle);
                if ($handle === '') {
                    continue;
                }

                if ($platform === self::LINKEDIN_URN_KEY) {
                    $urn = LinkedInOrg::normalizeUrn($handle);
                    if ($urn !== null) {
                        $handles[$platform] = $urn;
                    }

                    continue;
                }

                if ($platform === Platform::LinkedIn->value) {
                    // A URN/company URL typed into the display field is an org
                    // reference, not a name — route it to the urn key instead.
                    if (LinkedInOrg::looksLikeReference($handle)) {
                        $urn = LinkedInOrg::normalizeUrn($handle);
                        if ($urn !== null) {
                            $handles[self::LINKEDIN_URN_KEY] = $urn;
                        }

                        continue;
                    }

                    // '@'-only handles collapse to empty; drop so the label is used instead.
                    $handle = ltrim($handle, '@');
                    if ($handle === '') {
                        continue;
                    }
                }

                $handles[$platform] = $handle;
            }

            $normalized[] = [
                'id' => $id,
                'label' => trim((string) ($mention['label'] ?? 'Mention')) ?: 'Mention',
                'handles' => $handles,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{id: string, label: string, handles: array<string, string>}>  $mentions
     */
    private function resolveMentionTokens(string $text, array $mentions, string $platform): string
    {
        usort($mentions, static fn (array $left, array $right): int => strlen($right['label']) <=> strlen($left['label']));

        $resolved = $text;
        foreach ($mentions as $mention) {
            $resolved = str_replace($mention['label'], $this->mentionTextForPlatform($mention, $platform), $resolved);
        }

        $byId = [];
        foreach ($mentions as $mention) {
            $byId[$mention['id']] = $mention;
        }

        return (string) preg_replace_callback('/\{\{mention:([a-zA-Z0-9_-]+)\}\}/', function (array $matches) use ($byId, $platform): string {
            $mention = $byId[$matches[1]] ?? null;
            if ($mention === null) {
                return $matches[0];
            }

            return $this->mentionTextForPlatform($mention, $platform);
        }, $resolved);
    }

    /**
     * @param  array{id: string, label: string, handles: array<string, string>}  $mention
     */
    private function mentionTextForPlatform(array $mention, string $platform): string
    {
        $handle = trim((string) ($mention['handles'][$platform] ?? ''));
        if ($handle === '') {
            $handle = (string) $mention['label'];
        }

        if ($platform !== Platform::LinkedIn->value) {
            return $handle;
        }

        // LinkedIn tags are plain text unless a real org URN is stored, in which
        // case emit the inline `@[Name](urn:li:organization:ID)` annotation.
        $display = ltrim($handle, '@');
        if ($display === '') {
            $display = (string) $mention['label'];
        }

        $urn = trim((string) ($mention['handles'][self::LINKEDIN_URN_KEY] ?? ''));
        if ($urn !== '' && LinkedInOrg::isOrgUrn($urn)) {
            return '@['.$display.']('.$urn.')';
        }

        return $display;
    }

    /**
     * The account set id to persist on the post — only when the destination is a set
     * that actually belongs to the workspace. A foreign or unknown set id resolves to
     * null (it would yield zero targets anyway), preventing a dangling reference.
     *
     * @param  array{kind: string, id?: string|null, ids?: list<string>}  $destination
     */
    private function scopedAccountSetId(string $workspaceId, array $destination): ?string
    {
        if ($destination['kind'] !== 'set' || ! isset($destination['id'])) {
            return null;
        }

        return AccountSet::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($destination['id'])
            ->value('id');
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
