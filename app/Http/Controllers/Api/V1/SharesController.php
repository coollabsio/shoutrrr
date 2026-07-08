<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostShare;
use App\Models\User;
use App\Services\Posts\ShareService;
use App\Support\CursorPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharesController extends Controller
{
    public function index(Request $request, string $id): JsonResponse
    {
        $model = $this->findPostOrFail($id);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $model->shares()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(fn (PostShare $s): array => [
                'id' => $s->id,
                'expires_at' => $s->expires_at?->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
            ]);

        return response()->json(CursorPage::make($paginator));
    }

    public function store(Request $request, string $id, ShareService $shares): JsonResponse
    {
        $model = $this->findPostOrFail($id);

        $validated = $request->validate(['expires_at' => ['nullable', 'date']]);

        /** @var User $user */
        $user = $request->user();

        $expiresAt = ($validated['expires_at'] ?? null) !== null
            ? CarbonImmutable::parse($validated['expires_at'])
            : null;

        [$share, $tokenValue] = $shares->mint($model, $user, $expiresAt);

        return response()->json([
            'id' => $share->id,
            'url' => $shares->url($tokenValue),
            'expires_at' => $share->expires_at?->toIso8601String(),
        ], 201);
    }

    public function destroy(string $id, string $shareId): JsonResponse
    {
        $model = $this->findPostOrFail($id);

        $postShare = PostShare::query()->whereKey($shareId)->first();
        if ($postShare === null || $postShare->post_id !== $model->id) {
            abort(404, 'No share with that id exists for this post.');
        }

        $postShare->forceFill(['revoked_at' => now()])->save();

        return response()->json(['revoked' => true]);
    }

    protected function findPostOrFail(string $id): Post
    {
        return Post::query()->whereKey($id)->firstOr(fn () => abort(404, 'No post with that id exists in this workspace.'));
    }
}
