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
            $request->validated('alt_text'),
        );

        return response()->json(['media' => $media->toView()], 201);
    }

    public function update(UpdateReplyImageEditRequest $request, PostTargetReply $reply, PostMedia $media): JsonResponse
    {
        abort_unless($media->workspace_id === $reply->workspace_id, 404);
        // Animated media (GIF, or a GIF-browser WebP) has no editor client-side;
        // replacing one with a raster beautified frame would silently flatten the
        // animation. A WebP is editable only when it is a beautifier output (it
        // carries edit_settings) — a WebP without them is a GIF-browser animation.
        abort_if($media->isAttachOnlyImage(), 422, 'Animated images cannot be edited.');

        $updated = $this->media->replaceBeautified(
            $media,
            $request->file('composed'),
            $request->validated('settings'),
            $request->validated('alt_text'),
        );

        return response()->json(['media' => $updated->toView()]);
    }
}
