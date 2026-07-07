<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\PostListItem;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,scheduled,publishing,published,partial,failed,deleted'],
            'q' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $posts = Post::query()
            ->with(['author:id,name', 'targets'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['q'] ?? null, fn ($query, $q) => $query->where('base_text', 'like', "%{$q}%"))
            ->latest()
            ->limit($validated['limit'] ?? 20)
            ->get()
            ->map(fn (Post $post): array => PostListItem::make($post));

        return response()->json(['posts' => $posts]);
    }

    public function show(string $id): JsonResponse
    {
        $model = $this->findPostOrFail($id);

        return response()->json(['post' => PostView::make($model->load(['targets.account', 'media']))]);
    }

    protected function findPostOrFail(string $id): Post
    {
        return Post::query()->whereKey($id)->firstOr(fn () => abort(404, 'No post with that id exists in this workspace.'));
    }
}
