<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostImageEditRequest;
use App\Http\Requests\Post\UpdatePostImageEditRequest;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\JsonResponse;

class PostImageEditController extends Controller
{
    public function __construct(private readonly MediaStorageService $media) {}

    public function store(StorePostImageEditRequest $request, Post $post): JsonResponse
    {
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        $media = $this->media->storeBeautified(
            $post->workspace_id,
            $request->file('composed'),
            $request->file('source'),
            $request->validated('settings'),
        );

        return response()->json(['media' => $media->toView()], 201);
    }

    public function update(UpdatePostImageEditRequest $request, Post $post, PostMedia $media): JsonResponse
    {
        abort_unless($media->workspace_id === $post->workspace_id, 404);
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        $updated = $this->media->replaceBeautified(
            $media,
            $request->file('composed'),
            $request->validated('settings'),
        );

        return response()->json(['media' => $updated->toView()]);
    }
}
