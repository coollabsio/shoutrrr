<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Enums\SendStatus;
use App\Exceptions\TokenRefreshException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WorkspaceMentionController;
use App\Http\Requests\Engagement\RespondToReplyRequest;
use App\Jobs\SendReply;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTargetReply;
use App\Models\WorkspaceMention;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use App\Support\ReplyListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EngagementController extends Controller
{
    public function index(Request $request, InstanceSettings $settings): InertiaResponse
    {
        $account = $request->string('account')->toString();
        $platform = $request->string('platform')->toString();
        $target = $request->string('target')->toString();
        $post = $request->string('post')->toString();
        $unread = $request->boolean('unread');
        $archived = $request->boolean('archived');

        $apply = fn ($query) => $query
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

        $accounts = ConnectedAccount::query()->get(['id', 'handle', 'platform'])
            ->map(fn (ConnectedAccount $a): array => [
                'id' => $a->id,
                'handle' => $a->handle,
                'platform' => $a->platform->value,
            ])->all();

        // Posts with at least one live (inbound, unarchived) reply, plus a
        // running count, so the inbox can be filtered by which post drew them.
        // Qualify the columns: this closure runs inside whereHas/withCount joins
        // where `status`/`is_ours` also exist on posts and post_targets.
        $liveReplies = fn ($q) => $q
            ->where('post_target_replies.is_ours', false)
            ->where('post_target_replies.status', '!=', ReplyStatus::Archived->value);

        $posts = Post::query()
            ->whereHas('replies', $liveReplies)
            ->withCount(['replies as reply_count' => $liveReplies])
            ->orderByDesc('reply_count')
            ->limit(50)
            ->get()
            ->map(fn (Post $p): array => [
                'id' => $p->id,
                'excerpt' => $p->excerpt(),
                'count' => (int) $p->getAttribute('reply_count'),
            ])->all();

        return Inertia::render('engagement/index', [
            'replies' => Inertia::scroll(fn () => $this->conversationPaginator($apply, $request))->defer(),
            'filters' => [
                'account' => $account,
                'platform' => $platform,
                'target' => $target,
                'post' => $post,
                'unread' => $unread && ! $archived,
                'archived' => $archived,
            ],
            'facets' => ['accounts' => $accounts, 'posts' => $posts],
            'engagementEnabled' => [
                'x' => $settings->engagementPollingEnabled(Platform::X),
                'bluesky' => $settings->engagementPollingEnabled(Platform::Bluesky),
                'linkedin' => $settings->engagementPollingEnabled(Platform::LinkedIn),
            ],
            // LinkedIn engagement is off by default until the operator declares
            // their app is approved for the restricted Community Management scope.
            // The UI uses this to keep LinkedIn out of the "temporarily disabled"
            // banner in that expected-off state.
            'linkedinCommunityManagementEnabled' => $settings->linkedinCommunityManagementEnabled(),
            // The reply box reuses the composer's @-mention picker, so it needs
            // the same saved-mention library the composer receives.
            'savedMentions' => WorkspaceMention::withoutGlobalScopes()
                ->where('workspace_id', $request->user()->current_workspace_id)
                ->orderBy('name')
                ->get()
                ->map(fn (WorkspaceMention $mention): array => WorkspaceMentionController::view($mention))
                ->all(),
        ]);
    }

    public function thread(PostTargetReply $reply): JsonResponse
    {
        $onTarget = PostTargetReply::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $reply->workspace_id)
            ->where('post_target_id', $reply->post_target_id)
            ->with(['target.post', 'target.account'])
            ->get();

        $baseReply = $this->baseReplyFor($reply, $onTarget);
        $baseRemoteId = $baseReply->remote_reply_id;

        $conversation = $onTarget
            ->filter(fn (PostTargetReply $candidate): bool => $this->baseReplyFor($candidate, $onTarget)->remote_reply_id === $baseRemoteId)
            ->sortBy('remote_created_at');

        PostTargetReply::query()
            ->whereIn('id', $conversation->where('is_ours', false)->where('read_at', null)->pluck('id'))
            ->update(['read_at' => now()]);

        $thread = $conversation
            ->map(fn (PostTargetReply $r): array => ReplyListItem::make($r))
            ->values()
            ->all();

        return response()->json([
            'post_excerpt' => $reply->target?->post?->excerpt(),
            'thread' => $thread,
        ]);
    }

    public function markRead(PostTargetReply $reply): Response
    {
        PostTargetReply::query()
            ->whereIn('id', $this->inboundRepliesForBaseThread($reply)->pluck('id'))
            ->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function archive(PostTargetReply $reply): Response
    {
        PostTargetReply::query()
            ->whereIn('id', $this->inboundRepliesForBaseThread($reply)->pluck('id'))
            ->update(['status' => ReplyStatus::Archived->value]);

        return response()->noContent();
    }

    /**
     * @param  callable(Builder<PostTargetReply>): Builder<PostTargetReply>  $apply
     * @return LengthAwarePaginator<int, non-empty-array<string, mixed>>
     */
    private function conversationPaginator(callable $apply, Request $request): LengthAwarePaginator
    {
        $perPage = 25;
        $page = LengthAwarePaginator::resolveCurrentPage();
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
                    'conversation_key' => $this->conversationKeyFor($latestReply),
                    'reply_count' => (int) $group->getAttribute('reply_count'),
                    'unread_count' => $unreadCount,
                    'is_read' => $unreadCount === 0,
                ];
            })
            ->filter()
            ->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    private function conversationKeyFor(PostTargetReply $reply): string
    {
        return $reply->post_target_id.':'.($reply->conversation_remote_id ?? $reply->remote_reply_id);
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
     * @return Collection<int, PostTargetReply>
     */
    private function inboundRepliesForBaseThread(PostTargetReply $reply): Collection
    {
        $targetReplies = PostTargetReply::query()
            ->where('post_target_id', $reply->post_target_id)
            ->with(['target.post', 'target.account'])
            ->get();
        $baseRemoteId = $this->baseReplyFor($reply, $targetReplies)->remote_reply_id;

        return $targetReplies->filter(
            fn (PostTargetReply $candidate): bool => ! $candidate->is_ours
                && $this->baseReplyFor($candidate, $targetReplies)->remote_reply_id === $baseRemoteId
        );
    }

    public function respond(
        RespondToReplyRequest $request,
        PostTargetReply $reply,
        EngagementConnectorRegistry $registry,
        TokenManager $tokens,
    ): JsonResponse {
        $target = $reply->target;
        $account = $target?->account;

        if ($account === null) {
            $this->logActionFailure('respond', $reply, EngagementStatus::Unsupported, 'Account no longer connected.');

            return $this->actionError(EngagementStatus::Unsupported, 'This account is no longer connected.', 'Could not post the reply.');
        }

        if ($account->isDisabled()) {
            $this->logActionFailure('respond', $reply, EngagementStatus::Unsupported, 'Account disabled in the workspace.');

            return $this->actionError(EngagementStatus::Unsupported, 'This account is disabled in the workspace. Enable it to reply.', 'Could not post the reply.');
        }

        try {
            $credentials = $this->credentialsFor($account, $tokens);
        } catch (TokenRefreshException) {
            $this->logActionFailure('respond', $reply, EngagementStatus::AuthExpired, 'Token refresh failed.');

            return $this->actionError(EngagementStatus::AuthExpired, 'Could not authenticate with the platform. Reconnect the account.', 'Could not post the reply.');
        }

        $mediaIds = array_values($request->validated('media', []));

        if ($mediaIds === []) {
            $result = $registry->for($reply->platform)->postReply($account, $reply, (string) $request->validated('text'), $credentials);

            if (! $result->isOk()) {
                $this->logActionFailure('respond', $reply, $result->status, $result->message);

                return $this->actionError($result->status, $result->message, 'Could not post the reply.');
            }

            $reply->forceFill([
                'status' => ReplyStatus::Responded->value,
                'our_reply_remote_id' => $result->remoteReplyId,
            ])->save();

            $ourRow = PostTargetReply::withoutGlobalScopes()->create([
                'workspace_id' => $reply->workspace_id,
                'post_target_id' => $reply->post_target_id,
                'platform' => $reply->platform,
                'remote_reply_id' => $result->remoteReplyId,
                'remote_cid' => $result->remoteCid,
                'parent_remote_id' => $reply->remote_reply_id,
                'conversation_remote_id' => $reply->conversationRemoteIdForChild(),
                'author_handle' => $account->handle ?? '',
                'author_name' => $account->display_name,
                'author_avatar_url' => $account->avatar_url,
                'text' => (string) $request->validated('text'),
                'remote_created_at' => now(),
                'read_at' => now(),
                'status' => ReplyStatus::Pending->value,
                'is_ours' => true,
                'fetched_at' => now(),
            ]);

            return $this->respondedRow($ourRow);
        }

        // Media path: create the outgoing row in a sending state, then dispatch.
        $ourRow = PostTargetReply::withoutGlobalScopes()->create([
            'workspace_id' => $reply->workspace_id,
            'post_target_id' => $reply->post_target_id,
            'platform' => $reply->platform,
            'remote_reply_id' => 'pending:'.Str::uuid(),
            'parent_remote_id' => $reply->remote_reply_id,
            'conversation_remote_id' => $reply->conversationRemoteIdForChild(),
            'author_handle' => $account->handle ?? '',
            'author_name' => $account->display_name,
            'author_avatar_url' => $account->avatar_url,
            'text' => (string) $request->validated('text'),
            'remote_created_at' => now(),
            'read_at' => now(),
            'status' => ReplyStatus::Pending->value,
            'is_ours' => true,
            'send_status' => SendStatus::Sending->value,
            'fetched_at' => now(),
        ]);

        SendReply::dispatch($ourRow->id, $reply->id, $mediaIds, (string) $request->validated('text'), $reply->platform);

        return $this->respondedRow($ourRow);
    }

    /**
     * Serialize our freshly-created outgoing reply for the client, which swaps
     * it in for the optimistic bubble. Eager-load the relations ReplyListItem
     * reads: `create()` leaves them unloaded, so without this the serializer
     * would lazy-load them one by one (or emit nulls under strict mode).
     */
    private function respondedRow(PostTargetReply $ourRow): JsonResponse
    {
        $ourRow->load(['target.post', 'target.account']);

        return response()->json(['reply' => ReplyListItem::make($ourRow)], 201);
    }

    public function like(
        PostTargetReply $reply,
        EngagementConnectorRegistry $registry,
        TokenManager $tokens,
    ): JsonResponse {
        if ($reply->liked_at !== null) {
            return response()->json(['is_liked' => true]);
        }

        $account = $reply->target?->account;

        if ($account === null) {
            $this->logActionFailure('like', $reply, EngagementStatus::Unsupported, 'Account no longer connected.');

            return $this->actionError(EngagementStatus::Unsupported, 'This account is no longer connected.', 'Could not like this reply.');
        }

        try {
            $credentials = $this->credentialsFor($account, $tokens);
        } catch (TokenRefreshException) {
            $this->logActionFailure('like', $reply, EngagementStatus::AuthExpired, 'Token refresh failed.');

            return $this->actionError(EngagementStatus::AuthExpired, 'Could not authenticate with the platform. Reconnect the account.', 'Could not like this reply.');
        }

        $result = $registry->for($reply->platform)->likeReply($account, $reply, $credentials);

        if (! $result->isOk()) {
            $this->logActionFailure('like', $reply, $result->status, $result->message);

            return $this->actionError($result->status, $result->message, 'Could not like this reply.');
        }

        $reply->forceFill(['liked_at' => now(), 'like_remote_id' => $result->remoteId])->save();

        return response()->json(['is_liked' => true]);
    }

    public function unlike(
        PostTargetReply $reply,
        EngagementConnectorRegistry $registry,
        TokenManager $tokens,
    ): JsonResponse {
        if ($reply->liked_at === null) {
            return response()->json(['is_liked' => false]);
        }

        $account = $reply->target?->account;

        if ($account === null) {
            $this->logActionFailure('unlike', $reply, EngagementStatus::Unsupported, 'Account no longer connected.');

            return $this->actionError(EngagementStatus::Unsupported, 'This account is no longer connected.', 'Could not remove the like.');
        }

        try {
            $credentials = $this->credentialsFor($account, $tokens);
        } catch (TokenRefreshException) {
            $this->logActionFailure('unlike', $reply, EngagementStatus::AuthExpired, 'Token refresh failed.');

            return $this->actionError(EngagementStatus::AuthExpired, 'Could not authenticate with the platform. Reconnect the account.', 'Could not remove the like.');
        }

        $result = $registry->for($reply->platform)->unlikeReply($account, $reply, $reply->like_remote_id, $credentials);

        if (! $result->isOk()) {
            $this->logActionFailure('unlike', $reply, $result->status, $result->message);

            return $this->actionError($result->status, $result->message, 'Could not remove the like.');
        }

        $reply->forceFill(['liked_at' => null, 'like_remote_id' => null])->save();

        return response()->json(['is_liked' => false]);
    }

    public function destroyReply(
        PostTargetReply $reply,
        EngagementConnectorRegistry $registry,
        TokenManager $tokens,
    ): Response|JsonResponse {
        abort_unless($reply->is_ours, 403);

        $account = $reply->target?->account;

        if ($account === null) {
            $this->logActionFailure('delete', $reply, EngagementStatus::Unsupported, 'Account no longer connected.');

            return $this->actionError(EngagementStatus::Unsupported, 'This account is no longer connected.', 'Could not delete this reply.');
        }

        try {
            $credentials = $this->credentialsFor($account, $tokens);
        } catch (TokenRefreshException) {
            $this->logActionFailure('delete', $reply, EngagementStatus::AuthExpired, 'Token refresh failed.');

            return $this->actionError(EngagementStatus::AuthExpired, 'Could not authenticate with the platform. Reconnect the account.', 'Could not delete this reply.');
        }

        $result = $registry->for($reply->platform)->deleteReply($account, $reply, $credentials);

        if (! $result->isOk()) {
            $this->logActionFailure('delete', $reply, $result->status, $result->message);

            return $this->actionError($result->status, $result->message, 'Could not delete this reply.');
        }

        $reply->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialsFor(ConnectedAccount $account, TokenManager $tokens): array
    {
        return in_array($account->platform, [Platform::X, Platform::Bluesky, Platform::LinkedIn, Platform::Facebook, Platform::Instagram, Platform::Threads], true)
            ? $tokens->fresh($account)
            : [];
    }

    /**
     * Report a failed reply action as JSON at the status mapped from the
     * connector outcome. The client reads `message` in `onHttpException`; never
     * returns 422 (see EngagementStatus::httpStatus).
     */
    private function actionError(EngagementStatus $status, ?string $message, string $fallback): JsonResponse
    {
        return response()->json([
            'status' => $status->value,
            'message' => $message ?? $fallback,
        ], $status->httpStatus());
    }

    /**
     * Record why a reply action failed, so a like that the platform rejects is
     * diagnosable from the logs rather than only from the user's report.
     */
    private function logActionFailure(string $action, PostTargetReply $reply, EngagementStatus $status, ?string $message): void
    {
        Log::warning('engagement.action.failed', [
            'action' => $action,
            'platform' => $reply->platform->value,
            'reply_id' => $reply->id,
            'workspace_id' => $reply->workspace_id,
            'status' => $status->value,
            'message' => $message,
        ]);
    }
}
