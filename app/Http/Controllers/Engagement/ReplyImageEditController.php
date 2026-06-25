<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\StoreReplyImageEditRequest;
use App\Http\Requests\Engagement\UpdateReplyImageEditRequest;
use App\Models\PostMedia;
use App\Models\PostTargetReply;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\JsonResponse;

class ReplyImageEditController extends Controller
{
    public function __construct(private readonly MediaStorageService $media) {}

    public function store(StoreReplyImageEditRequest $request, PostTargetReply $reply): JsonResponse
    {
        $media = $this->media->storeBeautified(
            $reply->workspace_id,
            $request->file('composed'),
            $request->file('source'),
            $request->validated('settings'),
        );

        return response()->json(['media' => $media->toView()], 201);
    }

    public function update(UpdateReplyImageEditRequest $request, PostTargetReply $reply, PostMedia $media): JsonResponse
    {
        abort_unless($media->workspace_id === $reply->workspace_id, 404);

        $updated = $this->media->replaceBeautified(
            $media,
            $request->file('composed'),
            $request->validated('settings'),
        );

        return response()->json(['media' => $updated->toView()]);
    }
}
