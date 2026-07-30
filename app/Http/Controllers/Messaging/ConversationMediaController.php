<?php

declare(strict_types=1);

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\StoreConversationMediaRequest;
use App\Models\Conversation;
use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationMediaController extends Controller
{
    public function __construct(private readonly MediaStorageService $media) {}

    public function store(StoreConversationMediaRequest $request, Conversation $conversation): JsonResponse
    {
        $media = $this->media->store(
            $conversation->workspace_id,
            $request->file('file'),
            $request->validated('alt_text'),
        );

        return response()->json(['media' => $media->toView()], 201);
    }

    public function updateAlt(Conversation $conversation, PostMedia $media, Request $request): JsonResponse
    {
        abort_unless($media->workspace_id === $conversation->workspace_id, 404);
        abort_unless($media->direct_message_id === null, 404);

        $validated = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $media->update(['alt_text' => $validated['alt_text']]);

        return response()->json(['media' => $media->refresh()->toView()]);
    }

    public function destroy(Conversation $conversation, PostMedia $media): JsonResponse
    {
        abort_unless($media->workspace_id === $conversation->workspace_id, 404);
        abort_unless($media->direct_message_id === null, 404);

        $media->delete();

        return response()->json(['deleted' => true]);
    }
}
