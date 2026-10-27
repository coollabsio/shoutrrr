<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Dto\Engagement\ReplyActionResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Enums\SendStatus;
use App\Exceptions\TokenRefreshException;
use App\Jobs\SendReply;
use App\Models\ConnectedAccount;
use App\Models\PostTargetReply;
use App\Services\Publishing\TokenManager;
use App\Support\ReplyListItem;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The engagement inbox's reads and reply actions, shared by the Classic
 * engagement controller and the MCP engagement tools.
 */
class ReplyInbox
{
    public function __construct(
        private readonly EngagementConnectorRegistry $registry,
        private readonly TokenManager $tokens,
    ) {}

    /**
     * Scopes a reply query to the inbox view the filters describe.
     *
     * @return Closure(Builder<PostTargetReply>): Builder<PostTargetReply>
     */
    public function filter(
        string $account = '',
        string $platform = '',
        string $target = '',
        string $post = '',
        bool $unread = false,
        bool $archived = false,
    ): Closure {
        return fn (Builder $query): Builder => $query
            ->where('is_ours', false)
            ->when(
                $archived,
                fn ($q) => $q->where('status', ReplyStatus::Archived->value),
                fn ($q) => $q->where('status', '!=', ReplyStatus::Archived->value),
            )
            ->when($unread && ! $archived, fn ($q) => $q->whereNull('read_at'))
            ->when($platform !== '', fn ($q) => $q->whereHas('target',
                fn ($t) => $t->where('platform', $platform)))
            ->when($target !== '', fn ($q) => $q->where('post_target_id', $target))
            ->when($post !== '', fn ($q) => $q->whereHas('target',
                fn ($t) => $t->where('post_id', $post)))
            ->when($account !== '', fn ($q) => $q->whereHas('target',
                fn ($t) => $t->where('connected_account_id', $account)));
    }

    /**
     * One row per conversation (latest reply plus counts), newest first.
     *
     * @param  callable(Builder<PostTargetReply>): Builder<PostTargetReply>  $apply
     * @return LengthAwarePaginator<int, non-empty-array<string, mixed>>
     */
    public function conversations(callable $apply, int $perPage, int $page): LengthAwarePaginator
    {
        $groupsQuery = $apply(PostTargetReply::query())
            ->selectRaw('post_target_id, conversation_remote_id')
            ->selectRaw('max(remote_created_at) as latest_remote_created_at')
            ->selectRaw('count(*) as reply_count')
            ->selectRaw('sum(case when read_at is null then 1 else 0 end) as unread_count')
            ->groupBy('post_target_id', 'conversation_remote_id');

        $total = DB::query()
            ->fromSub((clone $groupsQuery)->toBase(), 'conversation_groups')
            ->count();

        $groups = (clone $groupsQuery)
            ->orderByDesc('latest_remote_created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $latestReplies = $groups->isEmpty()
            ? new EloquentCollection
            : $apply(PostTargetReply::query()->with(['target.post', 'target.account']))
                ->where(function ($query) use ($groups): void {
                    foreach ($groups as $group) {
                        $query->orWhere(function ($query) use ($group): void {
                            $query
                                ->where('post_target_id', $group->post_target_id)
                                ->where('remote_created_at', $group->getAttribute('latest_remote_created_at'));

                            if ($group->conversation_remote_id === null) {
                                $query->whereNull('conversation_remote_id');

                                return;
                            }

                            $query->where('conversation_remote_id', $group->conversation_remote_id);
                        });
                    }
                })
                ->orderByDesc('remote_created_at')
                ->get()
                ->keyBy(fn (PostTargetReply $reply): string => $reply->post_target_id.':'.$reply->conversation_remote_id);

        $items = $groups
            ->map(function (PostTargetReply $group) use ($latestReplies): ?array {
                $latestReply = $latestReplies->get($group->post_target_id.':'.$group->conversation_remote_id);

                if (! $latestReply instanceof PostTargetReply) {
                    return null;
                }

                $unreadCount = (int) $group->getAttribute('unread_count');

                return [
                    ...ReplyListItem::make($latestReply),
                    'conversation_key' => $latestReply->post_target_id.':'.($latestReply->conversation_remote_id ?? $latestReply->remote_reply_id),
                    'reply_count' => (int) $group->getAttribute('reply_count'),
                    'unread_count' => $unreadCount,
                    'is_read' => $unreadCount === 0,
                ];
            })
            ->filter()
            ->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * The whole conversation the reply belongs to, oldest first, marking its
     * inbound replies read (opening a thread reads it).
     *
     * @return Collection<int, PostTargetReply>
     */
    public function openThread(PostTargetReply $reply): Collection
    {
        $conversation = $this->conversationOf($reply)->sortBy('remote_created_at')->values();

        PostTargetReply::query()
            ->whereIn('id', $conversation->where('is_ours', false)->where('read_at', null)->pluck('id'))
            ->update(['read_at' => now()]);

        return $conversation;
    }

    public function markThreadRead(PostTargetReply $reply): void
    {
        PostTargetReply::query()
            ->whereIn('id', $this->inboundRepliesOf($reply)->pluck('id'))
            ->update(['read_at' => now()]);
    }

    /**
     * @param  callable(Builder<PostTargetReply>): Builder<PostTargetReply>  $apply
     */
    public function markAllRead(callable $apply): int
    {
        return $apply(PostTargetReply::query())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function archiveThread(PostTargetReply $reply): void
    {
        PostTargetReply::query()
            ->whereIn('id', $this->inboundRepliesOf($reply)->pluck('id'))
            ->update(['status' => ReplyStatus::Archived->value]);
    }

    /**
     * Post our reply under an inbound one. Text-only replies go out now; replies
     * with media are queued and return a row in the sending state.
     *
     * @param  list<string>  $mediaIds
     */
    public function respond(PostTargetReply $reply, string $text, array $mediaIds): PostTargetReply|ReplyPostResult
    {
        $account = $reply->target?->account;

        if ($account === null) {
            return $this->fail('respond', $reply, ReplyPostResult::unsupported('This account is no longer connected.'), 'Account no longer connected.');
        }

        if ($account->isDisabled()) {
            return $this->fail('respond', $reply, ReplyPostResult::unsupported('This account is disabled in the workspace. Enable it to reply.'), 'Account disabled in the workspace.');
        }

        $credentials = $this->credentialsFor($account);

        if ($credentials === null) {
            return $this->fail('respond', $reply, ReplyPostResult::authExpired('Could not authenticate with the platform. Reconnect the account.'), 'Token refresh failed.');
        }

        $ourRow = [
            'workspace_id' => $reply->workspace_id,
            'post_target_id' => $reply->post_target_id,
            'platform' => $reply->platform,
            'parent_remote_id' => $reply->remote_reply_id,
            'conversation_remote_id' => $reply->conversationRemoteIdForChild(),
            'author_handle' => $account->handle ?? '',
            'author_name' => $account->display_name,
            'author_avatar_url' => $account->avatar_url,
            'text' => $text,
            'remote_created_at' => now(),
            'read_at' => now(),
            'status' => ReplyStatus::Pending->value,
            'is_ours' => true,
            'fetched_at' => now(),
        ];

        if ($mediaIds === []) {
            $result = $this->registry->for($reply->platform)->postReply($account, $reply, $text, $credentials);

            if (! $result->isOk()) {
                return $this->fail('respond', $reply, $result);
            }

            $reply->forceFill([
                'status' => ReplyStatus::Responded->value,
                'our_reply_remote_id' => $result->remoteReplyId,
            ])->save();

            return $this->loaded(PostTargetReply::withoutGlobalScopes()->create([
                ...$ourRow,
                'remote_reply_id' => $result->remoteReplyId,
                'remote_cid' => $result->remoteCid,
            ]));
        }

        $sending = PostTargetReply::withoutGlobalScopes()->create([
            ...$ourRow,
            'remote_reply_id' => 'pending:'.Str::uuid(),
            'send_status' => SendStatus::Sending->value,
        ]);

        SendReply::dispatch($sending->id, $reply->id, $mediaIds, $text, $reply->platform);

        return $this->loaded($sending);
    }

    /**
     * Like or unlike the reply on the platform. A no-op when already in that state.
     */
    public function setLiked(PostTargetReply $reply, bool $liked): ReplyActionResult
    {
        $action = $liked ? 'like' : 'unlike';

        if (($reply->liked_at !== null) === $liked) {
            return ReplyActionResult::ok();
        }

        $account = $reply->target?->account;

        if ($account === null) {
            return $this->fail($action, $reply, ReplyActionResult::unsupported('This account is no longer connected.'), 'Account no longer connected.');
        }

        $credentials = $this->credentialsFor($account);

        if ($credentials === null) {
            return $this->fail($action, $reply, ReplyActionResult::authExpired('Could not authenticate with the platform. Reconnect the account.'), 'Token refresh failed.');
        }

        $connector = $this->registry->for($reply->platform);
        $result = $liked
            ? $connector->likeReply($account, $reply, $credentials)
            : $connector->unlikeReply($account, $reply, $reply->like_remote_id, $credentials);

        if (! $result->isOk()) {
            return $this->fail($action, $reply, $result);
        }

        $reply->forceFill($liked
            ? ['liked_at' => now(), 'like_remote_id' => $result->remoteId]
            : ['liked_at' => null, 'like_remote_id' => null])->save();

        return $result;
    }

    /**
     * Delete one of our own replies from the platform, then locally.
     */
    public function deleteOurReply(PostTargetReply $reply): ReplyActionResult
    {
        $account = $reply->target?->account;

        if ($account === null) {
            return $this->fail('delete', $reply, ReplyActionResult::unsupported('This account is no longer connected.'), 'Account no longer connected.');
        }

        $credentials = $this->credentialsFor($account);

        if ($credentials === null) {
            return $this->fail('delete', $reply, ReplyActionResult::authExpired('Could not authenticate with the platform. Reconnect the account.'), 'Token refresh failed.');
        }

        $result = $this->registry->for($reply->platform)->deleteReply($account, $reply, $credentials);

        if (! $result->isOk()) {
            return $this->fail('delete', $reply, $result);
        }

        $reply->delete();

        return $result;
    }

    /**
     * Every reply (ours included) in the reply's base conversation on its target.
     *
     * @return Collection<int, PostTargetReply>
     */
    private function conversationOf(PostTargetReply $reply): Collection
    {
        $onTarget = PostTargetReply::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $reply->workspace_id)
            ->where('post_target_id', $reply->post_target_id)
            ->with(['target.post', 'target.account'])
            ->get();

        $baseRemoteId = $this->baseReplyFor($reply, $onTarget)->remote_reply_id;

        return $onTarget->filter(
            fn (PostTargetReply $candidate): bool => $this->baseReplyFor($candidate, $onTarget)->remote_reply_id === $baseRemoteId
        );
    }

    /**
     * @return Collection<int, PostTargetReply>
     */
    private function inboundRepliesOf(PostTargetReply $reply): Collection
    {
        return $this->conversationOf($reply)->reject(fn (PostTargetReply $candidate): bool => $candidate->is_ours);
    }

    /**
     * @param  EloquentCollection<int, PostTargetReply>  $targetReplies
     */
    private function baseReplyFor(PostTargetReply $reply, EloquentCollection $targetReplies): PostTargetReply
    {
        static $indexCache = null;

        $indexCache ??= new \WeakMap;
        if (! isset($indexCache[$targetReplies])) {
            $indexCache[$targetReplies] = $targetReplies->keyBy('remote_reply_id');
        }

        $byRemoteId = $indexCache[$targetReplies];
        $rootRemoteId = $reply->target?->remote_id;
        $cursor = $reply;
        $visited = [];

        while (
            $cursor->parent_remote_id !== null
            && $cursor->parent_remote_id !== $rootRemoteId
            && $byRemoteId->has($cursor->parent_remote_id)
            && ! in_array($cursor->parent_remote_id, $visited, true)
        ) {
            $visited[] = $cursor->parent_remote_id;
            $cursor = $byRemoteId->get($cursor->parent_remote_id);
        }

        return $cursor;
    }

    /**
     * Eager-load the relations ReplyListItem reads: `create()` leaves them
     * unloaded, so the serializer would otherwise lazy-load them one by one.
     */
    private function loaded(PostTargetReply $ourRow): PostTargetReply
    {
        return $ourRow->load(['target.post', 'target.account']);
    }

    /**
     * Fresh platform credentials, or null when the token could not be refreshed.
     *
     * @return array<string, mixed>|null
     */
    private function credentialsFor(ConnectedAccount $account): ?array
    {
        if (! in_array($account->platform, [Platform::X, Platform::Bluesky, Platform::LinkedIn, Platform::Facebook, Platform::Instagram, Platform::Threads], true)) {
            return [];
        }

        try {
            return $this->tokens->fresh($account);
        } catch (TokenRefreshException) {
            return null;
        }
    }

    /**
     * Record why a reply action failed, so a like that the platform rejects is
     * diagnosable from the logs rather than only from the user's report.
     *
     * @template TResult of ReplyActionResult|ReplyPostResult
     *
     * @param  TResult  $result
     * @return TResult
     */
    private function fail(string $action, PostTargetReply $reply, ReplyActionResult|ReplyPostResult $result, ?string $logMessage = null): ReplyActionResult|ReplyPostResult
    {
        Log::warning('engagement.action.failed', [
            'action' => $action,
            'platform' => $reply->platform->value,
            'reply_id' => $reply->id,
            'workspace_id' => $reply->workspace_id,
            'status' => $result->status->value,
            'message' => $logMessage ?? $result->message,
        ]);

        return $result;
    }
}
