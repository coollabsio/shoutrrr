<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Dto\Post\DraftData;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostRequest;
use App\Jobs\DeletePostTarget;
use App\Models\AccountSet;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostStaleWriteException;
use App\Support\PostListItem;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(private readonly DraftService $drafts) {}

    public function index(Request $request): Response
    {
        $request->user()->can('viewAny', Post::class) ?: abort(403);

        $status = $request->string('status')->toString();
        $set = $request->string('set')->toString();
        $platform = $request->string('platform')->toString();
        $q = $request->string('q')->toString();

        $sets = AccountSet::query()->get(['id', 'name'])
            ->map(fn (AccountSet $s): array => ['id' => $s->id, 'name' => $s->name])->all();

        // The status-tab counts and the paginated list share the same set/platform/
        // search predicates (only the status filter differs), so define them once to
        // keep the two queries from drifting apart.
        $applyFilters = fn ($query) => $query
            ->when($set !== '', fn ($q2) => $q2->where('account_set_id', $set))
            ->when($platform !== '', fn ($q2) => $q2->whereHas('targets',
                fn ($t) => $t->where('platform', $platform)))
            ->when($q !== '', fn ($q2) => $q2->whereLike('base_text', "%{$q}%"));

        // Per-status tab counts honour the active search/platform/set filters but
        // not the status filter itself, so each tab shows how many posts of that
        // status match what's currently being looked at.
        $byStatus = $applyFilters(
            Post::query()->where('status', '!=', PostStatus::Deleted->value)
        )
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'all' => (int) $byStatus->sum(),
            'scheduled' => (int) ($byStatus[PostStatus::Scheduled->value] ?? 0),
            'draft' => (int) ($byStatus[PostStatus::Draft->value] ?? 0),
            'published' => (int) ($byStatus[PostStatus::Published->value] ?? 0),
            'missed' => (int) ($byStatus[PostStatus::Missed->value] ?? 0),
        ];

        return Inertia::render('posts/index', [
            'posts' => Inertia::scroll(fn () => $applyFilters(
                Post::query()
                    ->with(['author:id,name', 'targets'])
                    ->where('status', '!=', PostStatus::Deleted->value)
                    ->when($status !== '' && $status !== 'all', fn ($query) => $query->where('status', $status))
            )
                ->orderByRaw('COALESCE(scheduled_at, created_at) DESC')
                ->cursorPaginate(20)
                ->withQueryString()
                ->through(fn (Post $post): array => PostListItem::make($post)))->defer(),
            'filters' => ['status' => $status ?: 'all', 'set' => $set, 'platform' => $platform, 'q' => $q],
            'sets' => $sets,
            'counts' => $counts,
        ]);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->drafts->createDraft(
            $request->user()->current_workspace_id,
            $request->user(),
            $request->validated('destination'),
            (string) $request->validated('base_text'),
        );

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))], 201);
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        try {
            $updated = $this->drafts->updateDraft($post, DraftData::fromArray($request->validated()));
        } catch (PostStaleWriteException) {
            return response()->json([
                'post' => PostView::make($post->fresh(['targets.account', 'media'])),
                'message' => 'stale_write',
            ], 409);
        }

        return response()->json(['post' => PostView::make($updated->fresh(['targets.account', 'media']))]);
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $request->user()->can('delete', $post) ?: abort(403);

        $post->loadMissing('targets');

        $hadBeenPublished = in_array($post->status, [
            PostStatus::Published, PostStatus::Partial, PostStatus::Failed,
        ], true);

        if (! $hadBeenPublished) {
            $post->delete();

            return redirect()->route('posts.index')->with('success', 'Post deleted.');
        }

        $post->targets
            ->filter(fn (PostTarget $t): bool => $t->remote_id !== null)
            ->each(fn (PostTarget $t) => DeletePostTarget::dispatch($t));

        $post->forceFill([
            'status' => PostStatus::Deleted->value,
            'deleted_at' => now(),
        ])->save();

        return redirect()->route('posts.index')->with('success', 'Post deleted from connected accounts where possible.');
    }
}
