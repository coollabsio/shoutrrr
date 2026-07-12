<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Dto\Post\DraftData;
use App\Enums\PostStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesWorkspacePost;
use App\Http\Controllers\Controller;
use App\Jobs\DeletePostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostStaleWriteException;
use App\Support\CursorPage;
use App\Support\PostListItem;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\Rule;

class PostsController extends Controller
{
    use ResolvesWorkspacePost;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,scheduled,publishing,published,partial,failed,deleted'],
            'q' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Post::query()
            ->with(['author:id,name', 'targets'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['q'] ?? null, fn ($query, $q) => $query->whereLike('base_text', "%{$q}%"))
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(fn (Post $post): array => PostListItem::make($post));

        return response()->json(CursorPage::make($paginator));
    }

    public function show(string $id): JsonResponse
    {
        $model = $this->findPostOrFail($id);

        return response()->json(['post' => PostView::make($model->load(['targets.account', 'media']))]);
    }

    public function store(Request $request, DraftService $drafts): JsonResponse
    {
        $this->authorize('create', Post::class);

        $validated = $request->validate([
            'base_text' => ['present', 'nullable', 'string'],
            'segments' => ['array'],
            'segments.*' => ['string'],
            'mentions' => ['array'],
            'mentions.*.id' => ['required', 'string'],
            'mentions.*.label' => ['required', 'string'],
            'mentions.*.handles' => ['array'],
            'mentions.*.handles.x' => ['nullable', 'string'],
            'mentions.*.handles.bluesky' => ['nullable', 'string'],
            'mentions.*.handles.linkedin' => ['nullable', 'string'],
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $segments = isset($validated['segments']) && $validated['segments'] !== []
            ? array_values($validated['segments'])
            : [(string) ($validated['base_text'] ?? '')];

        $post = $drafts->createDraft(
            (string) Context::get('workspace_id'),
            $user,
            $validated['destination'],
            $segments,
            $validated['mentions'] ?? [],
        );

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))], 201);
    }

    public function update(Request $request, string $id, DraftService $drafts): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);

        $validated = $request->validate([
            'base_text' => ['present', 'nullable', 'string'],
            'segments' => ['array'],
            'segments.*' => ['string'],
            'mentions' => ['array'],
            'mentions.*.id' => ['required', 'string'],
            'mentions.*.label' => ['required', 'string'],
            'mentions.*.handles' => ['array'],
            'mentions.*.handles.x' => ['nullable', 'string'],
            'mentions.*.handles.bluesky' => ['nullable', 'string'],
            'mentions.*.handles.linkedin' => ['nullable', 'string'],
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.auto_split' => ['boolean'],
            'targets.*.content_override' => ['nullable', 'array'],
            'targets.*.content_override.text' => ['nullable', 'string'],
            'targets.*.content_override.media_ids' => ['array'],
            'targets.*.content_override.media_ids.*' => ['string'],
            'media_ids' => ['array'],
            'media_ids.*' => ['string'],
            'expected_updated_at' => ['nullable', 'string'],
        ]);

        try {
            $updated = $drafts->updateDraft($model, DraftData::fromArray($validated));
        } catch (PostStaleWriteException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(['post' => PostView::make($updated->fresh(['targets.account', 'media']))]);
    }

    public function destroy(string $id): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('delete', $model);

        $hadBeenPublished = in_array($model->status, [PostStatus::Published, PostStatus::Partial, PostStatus::Failed], true);

        $model->loadMissing('targets');

        if (! $hadBeenPublished) {
            $model->delete();

            return response()->json(['deleted' => true, 'remote' => false]);
        }

        $model->targets
            ->filter(fn (PostTarget $t): bool => $t->remote_id !== null)
            ->each(fn (PostTarget $t) => DeletePostTarget::dispatch($t));

        $model->forceFill(['status' => PostStatus::Deleted->value, 'deleted_at' => now()])->save();

        return response()->json(['deleted' => true, 'remote' => true, 'message' => 'Remote deletion queued for published targets.']);
    }
}
