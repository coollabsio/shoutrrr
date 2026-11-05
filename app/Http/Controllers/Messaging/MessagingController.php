<?php

declare(strict_types=1);

namespace App\Http\Controllers\Messaging;

use App\Dto\Messaging\MessageSendResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\RespondToMessageRequest;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Services\Messaging\ConversationReplier;
use App\Support\ConversationListItem;
use App\Support\MessageListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MessagingController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $workspaceId = $request->user()->current_workspace_id;
        $showArchived = $request->boolean('archived');

        return Inertia::render('messages/index', [
            'conversations' => Inertia::scroll(fn () => Conversation::query()
                ->where('workspace_id', $workspaceId)
                ->when($showArchived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
                ->with('account')
                ->orderByDesc('last_message_at')
                ->paginate(30)
                ->through(fn (Conversation $c) => ConversationListItem::make($c)))->defer(),
            'filters' => ['archived' => $showArchived],
        ]);
    }

    public function thread(Conversation $conversation): JsonResponse
    {
        $messages = $conversation->messages()
            ->with('media')
            ->orderBy('remote_created_at')
            ->get()
            ->map(fn (DirectMessage $m) => MessageListItem::make($m));

        return response()->json(['conversation' => ConversationListItem::make($conversation), 'messages' => $messages]);
    }

    public function markRead(Conversation $conversation): Response
    {
        $conversation->forceFill(['read_at' => now(), 'unread_count' => 0])->save();

        return response()->noContent();
    }

    public function archive(Conversation $conversation): Response
    {
        $conversation->forceFill(['archived_at' => now()])->save();

        return response()->noContent();
    }

    public function respond(RespondToMessageRequest $request, Conversation $conversation, ConversationReplier $replier): JsonResponse
    {
        /** @var list<string> $mediaIds */
        $mediaIds = $request->validated('media', []);
        $outcome = $replier->reply($conversation, $request->string('text')->toString(), $mediaIds);

        if ($outcome instanceof MessageSendResult) {
            return response()->json(['status' => $outcome->status->value, 'message' => $outcome->excerpt], $outcome->status->httpStatus());
        }

        return response()->json(['message' => MessageListItem::make($outcome)], 201);
    }
}
