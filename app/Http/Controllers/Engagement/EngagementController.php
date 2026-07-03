<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Enums\SendStatus;
use App\Exceptions\TokenRefreshException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\RespondToReplyRequest;
use App\Jobs\SendReply;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTargetReply;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use App\Support\ReplyListItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    public function markRead(Request $request, PostTargetReply $reply): RedirectResponse|Response
    {
        $this->inboundRepliesForBaseThread($reply)
            ->each(fn (PostTargetReply $threadReply): bool => $threadReply->forceFill(['read_at' => now()])->save());

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back();
    }

    public function archive(Request $request, PostTargetReply $reply): RedirectResponse|Response
    {
        $this->inboundRepliesForBaseThread($reply)
            ->each(fn (PostTargetReply $threadReply): bool => $threadReply->forceFill(['status' => ReplyStatus::Archived->value])->save());

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back();
    }

    /**
     * @param  callable(mixed): mixed  $apply
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function conversationPaginator(callable $apply, Request $request): LengthAwarePaginator
    {
        $inboundReplies = $apply(PostTargetReply::query()->with(['target.post', 'target.account']))
            ->orderByDesc('remote_created_at')
            ->get();

        $targetReplies = PostTargetReply::query()
            ->whereIn('post_target_id', $inboundReplies->pluck('post_target_id')->unique()->values())
            ->with(['target.post', 'target.account'])
            ->get();

        $items = $inboundReplies
            ->groupBy(fn (PostTargetReply $reply): string => $this->conversationKeyFor($reply, $targetReplies))
            ->map(function ($group, string $conversationKey): array {
                /** @var PostTargetReply $latestReply */
                $latestReply = $group->sortByDesc('remote_created_at')->first();
                $unreadCount = $group->where('read_at', null)->count();

                return [
                    ...ReplyListItem::make($latestReply),
                    'conversation_key' => $conversationKey,
                    'reply_count' => $group->count(),
                    'unread_count' => $unreadCount,
                    'is_read' => $unreadCount === 0,
                ];
            })
            ->sortByDesc('remote_created_at')
            ->values();

        $perPage = 25;
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /**
     * @param  EloquentCollection<int, PostTargetReply>  $targetReplies
     */
    private function conversationKeyFor(PostTargetReply $reply, EloquentCollection $targetReplies): string
    {
        $baseReply = $this->baseReplyFor($reply, $targetReplies);

        return $reply->post_target_id.':'.$baseReply->remote_reply_id;
    }

    /**
     * @param  EloquentCollection<int, PostTargetReply>  $targetReplies
     */
    private function baseReplyFor(PostTargetReply $reply, EloquentCollection $targetReplies): PostTargetReply
    {
        $byRemoteId = $targetReplies->keyBy('remote_reply_id');
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
    ): RedirectResponse {
        $target = $reply->target;
        $account = $target?->account;

        if ($account === null) {
            return back()->with('error', 'This account is no longer connected.');
        }

        try {
            $credentials = in_array($account->platform, [Platform::X, Platform::Bluesky, Platform::LinkedIn], true)
                ? $tokens->fresh($account)
                : [];
        } catch (TokenRefreshException) {
            return back()->with('error', 'Could not authenticate with the platform. Reconnect the account.');
        }

        $mediaIds = array_values($request->validated('media', []));

        if ($mediaIds === []) {
            $result = $registry->for($reply->platform)->postReply($account, $reply, (string) $request->validated('text'), $credentials);

            if (! $result->isOk()) {
                return back()->with('error', $result->message ?? 'Could not post the reply.');
            }

            $reply->forceFill([
                'status' => ReplyStatus::Responded->value,
                'our_reply_remote_id' => $result->remoteReplyId,
            ])->save();

            PostTargetReply::withoutGlobalScopes()->create([
                'workspace_id' => $reply->workspace_id,
                'post_target_id' => $reply->post_target_id,
                'platform' => $reply->platform,
                'remote_reply_id' => $result->remoteReplyId,
                'remote_cid' => $result->remoteCid,
                'parent_remote_id' => $reply->remote_reply_id,
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

            return back()->with('success', 'Reply sent.');
        }

        // Media path: create the outgoing row in a sending state, then dispatch.
        $ourRow = PostTargetReply::withoutGlobalScopes()->create([
            'workspace_id' => $reply->workspace_id,
            'post_target_id' => $reply->post_target_id,
            'platform' => $reply->platform,
            'remote_reply_id' => 'pending:'.Str::uuid(),
            'parent_remote_id' => $reply->remote_reply_id,
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

        return back()->with('success', 'Sending your reply…');
    }

    public function like(
        PostTargetReply $reply,
        EngagementConnectorRegistry $registry,
        TokenManager $tokens,
    ): RedirectResponse {
        if ($reply->liked_at !== null) {
            return back();
        }

        $account = $reply->target?->account;

        if ($account === null) {
            return back()->with('error', 'This account is no longer connected.');
        }

        try {
            $credentials = $this->credentialsFor($account, $tokens);
        } catch (TokenRefreshException) {
            return back()->with('error', 'Could not authenticate with the platform. Reconnect the account.');
        }

        $result = $registry->for($reply->platform)->likeReply($account, $reply, $credentials);

        if (! $result->isOk()) {
            return back()->with('error', $result->message ?? 'Could not like this reply.');
        }

        $reply->forceFill(['liked_at' => now(), 'like_remote_id' => $result->remoteId])->save();

        return back();
    }

    public function unlike(
        PostTargetReply $reply,
        EngagementConnectorRegistry $registry,
        TokenManager $tokens,
    ): RedirectResponse {
        if ($reply->liked_at === null) {
            return back();
        }

        $account = $reply->target?->account;

        if ($account === null) {
            return back()->with('error', 'This account is no longer connected.');
        }

        try {
            $credentials = $this->credentialsFor($account, $tokens);
        } catch (TokenRefreshException) {
            return back()->with('error', 'Could not authenticate with the platform. Reconnect the account.');
        }

        $result = $registry->for($reply->platform)->unlikeReply($account, $reply, $reply->like_remote_id, $credentials);

        if (! $result->isOk()) {
            return back()->with('error', $result->message ?? 'Could not remove the like.');
        }

        $reply->forceFill(['liked_at' => null, 'like_remote_id' => null])->save();

        return back();
    }

    public function destroyReply(
        PostTargetReply $reply,
        EngagementConnectorRegistry $registry,
        TokenManager $tokens,
    ): RedirectResponse {
        abort_unless($reply->is_ours, 403);

        $account = $reply->target?->account;

        if ($account === null) {
            return back()->with('error', 'This account is no longer connected.');
        }

        try {
            $credentials = $this->credentialsFor($account, $tokens);
        } catch (TokenRefreshException) {
            return back()->with('error', 'Could not authenticate with the platform. Reconnect the account.');
        }

        $result = $registry->for($reply->platform)->deleteReply($account, $reply, $credentials);

        if (! $result->isOk()) {
            return back()->with('error', $result->message ?? 'Could not delete this reply.');
        }

        $reply->delete();

        return back()->with('success', 'Reply deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialsFor(ConnectedAccount $account, TokenManager $tokens): array
    {
        return in_array($account->platform, [Platform::X, Platform::Bluesky, Platform::LinkedIn], true)
            ? $tokens->fresh($account)
            : [];
    }
}
