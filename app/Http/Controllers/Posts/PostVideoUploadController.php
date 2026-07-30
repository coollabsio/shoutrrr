<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Concerns\StoresPresignedVideo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\SignVideoUploadRequest;
use App\Http\Requests\Post\StoreVideoRequest;
use App\Models\Post;
use Illuminate\Http\JsonResponse;

class PostVideoUploadController extends Controller
{
    use StoresPresignedVideo;

    public function url(SignVideoUploadRequest $request, Post $post): JsonResponse
    {
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        return $this->signVideoUpload((string) $post->workspace_id);
    }

    public function store(StoreVideoRequest $request, Post $post): JsonResponse
    {
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        return $this->storeUploadedVideo(
            $request->validated(),
            (string) $post->workspace_id,
            ['post_id' => $post->id],
        );
    }
}
