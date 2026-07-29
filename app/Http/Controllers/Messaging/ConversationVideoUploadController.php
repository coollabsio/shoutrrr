<?php

declare(strict_types=1);

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Concerns\StoresPresignedVideo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SignConversationVideoUploadRequest;
use App\Http\Requests\Messaging\StoreConversationVideoRequest;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;

class ConversationVideoUploadController extends Controller
{
    use StoresPresignedVideo;

    public function url(SignConversationVideoUploadRequest $request, Conversation $conversation): JsonResponse
    {
        return $this->signVideoUpload((string) $conversation->workspace_id);
    }

    public function store(StoreConversationVideoRequest $request, Conversation $conversation): JsonResponse
    {
        return $this->storeUploadedVideo(
            $request->validated(),
            (string) $conversation->workspace_id,
            ['conversation_id' => $conversation->id],
        );
    }
}
