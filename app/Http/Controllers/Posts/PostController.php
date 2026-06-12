<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Dto\Post\DraftData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostRequest;
use App\Models\Post;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostStaleWriteException;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class PostController extends Controller
{
    public function __construct(private readonly DraftService $drafts) {}

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

    public function showJson(Post $post): JsonResponse
    {
        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }

    public function destroy(Post $post): RedirectResponse
    {
        $post->delete();

        return back()->with('success', 'Draft deleted.');
    }
}
