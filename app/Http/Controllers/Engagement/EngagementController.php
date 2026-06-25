<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Exceptions\TokenRefreshException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\RespondToReplyRequest;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\ReplyListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EngagementController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $account = $request->string('account')->toString();
        $platform = $request->string('platform')->toString();
        $target = $request->string('target')->toString();
        $unread = $request->boolean('unread');

        $apply = fn ($query) => $query
            ->where('is_ours', false)
            ->where('status', '!=', ReplyStatus::Archived->value)
            ->when($unread, fn ($q) => $q->whereNull('read_at'))
            ->when($platform !== '', fn ($q) => $q->where('platform', $platform))
            ->when($target !== '', fn ($q) => $q->where('post_target_id', $target))
            ->when($account !== '', fn ($q) => $q->whereHas('target',
                fn ($t) => $t->where('connected_account_id', $account)));

        $accounts = ConnectedAccount::query()->get(['id', 'handle', 'platform'])
            ->map(fn (ConnectedAccount $a): array => [
                'id' => $a->id,
                'handle' => $a->handle,
                'platform' => $a->platform->value,
            ])->all();

        return Inertia::render('engagement/index', [
            'replies' => Inertia::scroll(fn () => $apply(
                PostTargetReply::query()->with(['target.post', 'target.account'])
            )
                ->orderByDesc('remote_created_at')
                ->cursorPaginate(25)
                ->withQueryString()
                ->through(fn (PostTargetReply $reply): array => ReplyListItem::make($reply)))->defer(),
            'filters' => [
                'account' => $account,
                'platform' => $platform,
                'target' => $target,
                'unread' => $unread,
            ],
            'facets' => ['accounts' => $accounts],
        ]);
    }

    public function thread(PostTargetReply $reply): JsonResponse
    {
        if ($reply->read_at === null) {
            $reply->forceFill(['read_at' => now()])->save();
        }

        $onTarget = PostTargetReply::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $reply->workspace_id)
            ->where('post_target_id', $reply->post_target_id)
            ->with(['target.post', 'target.account'])
            ->get();

        $byRemoteId = $onTarget->keyBy('remote_reply_id');

        $ancestors = collect();
        $visited = [];
        $cursor = $reply;
        while (
            $cursor->parent_remote_id !== null
            && $byRemoteId->has($cursor->parent_remote_id)
            && ! in_array($cursor->parent_remote_id, $visited, true)
        ) {
            $visited[] = $cursor->parent_remote_id;
            $cursor = $byRemoteId->get($cursor->parent_remote_id);
            $ancestors->prepend($cursor);
        }

        $children = $onTarget->filter(fn (PostTargetReply $r): bool => $r->parent_remote_id === $reply->remote_reply_id);

        $thread = $ancestors->push($reply)->merge($children->sortBy('remote_created_at'))
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
        $reply->forceFill(['read_at' => now()])->save();

        return response()->noContent();
    }

    public function archive(PostTargetReply $reply): Response
    {
        $reply->forceFill(['status' => ReplyStatus::Archived->value])->save();

        return response()->noContent();
    }

    public function refresh(PostTarget $target): RedirectResponse
    {
        FetchPostTargetReplies::dispatch($target);

        return back()->with('success', 'Checking for new replies…');
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
            $credentials = in_array($account->platform, [Platform::X, Platform::Bluesky], true)
                ? $tokens->fresh($account)
                : [];
        } catch (TokenRefreshException) {
            return back()->with('error', 'Could not authenticate with the platform. Reconnect the account.');
        }

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
}
