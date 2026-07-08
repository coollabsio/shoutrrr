<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use RuntimeException;

class MediaController extends Controller
{
    public function store(Request $request, MediaStorageService $media): JsonResponse
    {
        $this->authorize('create', Post::class);

        $validated = $request->validate([
            'file' => ['required_without:url', 'file', 'image', 'max:8192'],
            'url' => ['required_without:file', 'url'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $workspaceId = (string) Context::get('workspace_id');
        $alt = $validated['alt_text'] ?? null;

        try {
            $stored = $request->hasFile('file')
                ? $media->store($workspaceId, $request->file('file'), $alt)
                : $media->storeFromUrl($workspaceId, $validated['url'], $alt);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'id' => $stored->id,
            'mime' => $stored->mime,
            'width' => $stored->width,
            'height' => $stored->height,
            'alt_text' => $stored->alt_text,
        ], 201);
    }

    public function destroy(string $mediaId): JsonResponse
    {
        $model = PostMedia::query()->whereKey($mediaId)
            ->firstOr(fn () => abort(404, 'No media with that id exists in this workspace.'));

        $model->delete();

        return response()->json(['deleted' => true, 'id' => $mediaId]);
    }
}
