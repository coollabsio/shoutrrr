<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostMediaRequest;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\JsonResponse;

class PostMediaController extends Controller
{
    public function __construct(private readonly MediaStorageService $media) {}

    public function store(StorePostMediaRequest $request, Post $post): JsonResponse
    {
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        $media = $this->media->store(
            $post->workspace_id,
            $request->file('file'),
            $request->validated('alt_text'),
        );

        return response()->json(['media' => $media->toView()], 201);
    }

    public function destroy(Post $post, PostMedia $media): JsonResponse
    {
        abort_unless($media->workspace_id === $post->workspace_id, 404);

        $media->delete();

        return response()->json(['deleted' => true]);
    }
}
