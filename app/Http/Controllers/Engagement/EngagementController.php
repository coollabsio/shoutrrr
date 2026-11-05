<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Dto\Engagement\ReplyPostResult;
use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WorkspaceMentionController;
use App\Http\Requests\Engagement\RespondToReplyRequest;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTargetReply;
use App\Models\WorkspaceMention;
use App\Services\Engagement\ReplyInbox;
use App\Support\InstanceSettings;
use App\Support\ReplyListItem;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EngagementController extends Controller
{
    public function index(Request $request, InstanceSettings $settings, ReplyInbox $inbox): InertiaResponse
    {
        $account = $request->string('account')->toString();
        $platform = $request->string('platform')->toString();
        $target = $request->string('target')->toString();
        $post = $request->string('post')->toString();
        $unread = $request->boolean('unread');
        $archived = $request->boolean('archived');
        $apply = $this->inboxFilter($request, $inbox);

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
            'replies' => Inertia::scroll(fn () => $inbox
                ->conversations($apply, 25, LengthAwarePaginator::resolveCurrentPage())
                ->withPath($request->url())
                ->appends($request->query()))->defer(),
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

    public function thread(PostTargetReply $reply, ReplyInbox $inbox): JsonResponse
    {
        return response()->json([
            'post_excerpt' => $reply->target?->post?->excerpt(),
            'thread' => $inbox->openThread($reply)
                ->map(fn (PostTargetReply $r): array => ReplyListItem::make($r))
                ->all(),
        ]);
    }

    public function markRead(PostTargetReply $reply, ReplyInbox $inbox): Response
    {
        $inbox->markThreadRead($reply);

        return response()->noContent();
    }

    public function markAllRead(Request $request, ReplyInbox $inbox): Response
    {
        $inbox->markAllRead($this->inboxFilter($request, $inbox));

        return response()->noContent();
    }

    public function archive(PostTargetReply $reply, ReplyInbox $inbox): Response
    {
        $inbox->archiveThread($reply);

        return response()->noContent();
    }

    /**
     * @return Closure(Builder<PostTargetReply>): Builder<PostTargetReply>
     */
    private function inboxFilter(Request $request, ReplyInbox $inbox): Closure
    {
        return $inbox->filter(
            account: $request->string('account')->toString(),
            platform: $request->string('platform')->toString(),
            target: $request->string('target')->toString(),
            post: $request->string('post')->toString(),
            unread: $request->boolean('unread'),
            archived: $request->boolean('archived'),
        );
    }

    public function respond(RespondToReplyRequest $request, PostTargetReply $reply, ReplyInbox $inbox): JsonResponse
    {
        $result = $inbox->respond($reply, (string) $request->validated('text'), array_values($request->validated('media', [])));

        if ($result instanceof ReplyPostResult) {
            return $this->actionError($result->status, $result->message, 'Could not post the reply.');
        }

        // The client swaps this row in for its optimistic bubble.
        return response()->json(['reply' => ReplyListItem::make($result)], 201);
    }

    public function like(PostTargetReply $reply, ReplyInbox $inbox): JsonResponse
    {
        $result = $inbox->setLiked($reply, true);

        return $result->isOk()
            ? response()->json(['is_liked' => true])
            : $this->actionError($result->status, $result->message, 'Could not like this reply.');
    }

    public function unlike(PostTargetReply $reply, ReplyInbox $inbox): JsonResponse
    {
        $result = $inbox->setLiked($reply, false);

        return $result->isOk()
            ? response()->json(['is_liked' => false])
            : $this->actionError($result->status, $result->message, 'Could not remove the like.');
    }

    public function destroyReply(PostTargetReply $reply, ReplyInbox $inbox): Response|JsonResponse
    {
        abort_unless($reply->is_ours, 403);

        $result = $inbox->deleteOurReply($reply);

        return $result->isOk()
            ? response()->noContent()
            : $this->actionError($result->status, $result->message, 'Could not delete this reply.');
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
}
