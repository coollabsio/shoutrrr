<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Concerns\StoresPresignedVideo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\SignReplyVideoUploadRequest;
use App\Http\Requests\Engagement\StoreReplyVideoRequest;
use App\Models\PostTargetReply;
use Illuminate\Http\JsonResponse;

class ReplyVideoUploadController extends Controller
{
    use StoresPresignedVideo;

    public function url(SignReplyVideoUploadRequest $request, PostTargetReply $reply): JsonResponse
    {
        return $this->signVideoUpload((string) $reply->workspace_id);
    }

    public function store(StoreReplyVideoRequest $request, PostTargetReply $reply): JsonResponse
    {
        return $this->storeUploadedVideo(
            $request->validated(),
            (string) $reply->workspace_id,
            ['reply_id' => $reply->id],
        );
    }
}
