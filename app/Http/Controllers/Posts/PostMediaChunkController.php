<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostMediaChunkRequest;
use App\Models\Post;
use App\Services\Posts\MediaChunkService;
use Illuminate\Http\JsonResponse;

class PostMediaChunkController extends Controller
{
    public function __construct(private readonly MediaChunkService $chunks) {}

    public function store(StorePostMediaChunkRequest $request, Post $post): JsonResponse
    {
        $size = $this->chunks->append(
            $post->workspace_id,
            $request->validated('upload_id'),
            (int) $request->validated('index'),
            $request->file('chunk'),
        );

        abort_if($size > Platform::maxVideoBytesCeiling(), 422, 'Video exceeds the maximum allowed size.');

        if (! $request->isFinalChunk()) {
            return response()->json([
                'received' => (int) $request->validated('index') + 1,
                'total' => (int) $request->validated('total'),
            ]);
        }

        $media = $this->chunks->finalize($post->workspace_id, $request->validated('upload_id'), [
            'duration_seconds' => (int) $request->validated('duration_seconds'),
            'width' => (int) $request->validated('width'),
            'height' => (int) $request->validated('height'),
            'alt_text' => $request->validated('alt_text'),
        ]);

        return response()->json(['media' => [
            'id' => $media->id,
            'url' => $media->url(),
            'mime' => $media->mime,
            'kind' => $media->kind,
            'duration_seconds' => $media->duration_seconds,
            'alt_text' => $media->alt_text,
        ]], 201);
    }
}
