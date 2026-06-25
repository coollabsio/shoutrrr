<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\StoreReplyMediaRequest;
use App\Models\PostMedia;
use App\Models\PostTargetReply;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\JsonResponse;

class ReplyMediaController extends Controller
{
    public function __construct(private readonly MediaStorageService $media) {}

    public function store(StoreReplyMediaRequest $request, PostTargetReply $reply): JsonResponse
    {
        $media = $this->media->store(
            $reply->workspace_id,
            $request->file('file'),
            $request->validated('alt_text'),
        );

        return response()->json(['media' => $media->toView()], 201);
    }

    public function destroy(PostTargetReply $reply, PostMedia $media): JsonResponse
    {
        abort_unless($media->workspace_id === $reply->workspace_id, 404);

        $media->delete();

        return response()->json(['deleted' => true]);
    }
}
