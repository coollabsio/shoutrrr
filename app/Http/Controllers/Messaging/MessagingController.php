<?php

declare(strict_types=1);

namespace App\Http\Controllers\Messaging;

use App\Enums\EngagementStatus;
use App\Enums\MessageDirection;
use App\Enums\SendStatus;
use App\Http\Requests\RespondToMessageRequest;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Services\Messaging\MessageConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\ConversationListItem;
use App\Support\MessageListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MessagingController
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

    public function respond(RespondToMessageRequest $request, Conversation $conversation, MessageConnectorRegistry $registry, TokenManager $tokens): JsonResponse
    {
        if (! $conversation->canReplyNow()) {
            return response()->json(['status' => EngagementStatus::Unsupported->value, 'message' => 'The 24-hour reply window has closed.'], EngagementStatus::Unsupported->httpStatus());
        }

        $account = $conversation->account;
        $credentials = $tokens->fresh($account);
        $result = $registry->for($conversation->platform)->sendMessage($account, $conversation, $request->string('text')->toString(), $credentials);

        if (! $result->isOk()) {
            return response()->json(['status' => $result->status->value, 'message' => $result->excerpt], $result->status->httpStatus());
        }

        $row = DirectMessage::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'remote_message_id' => $result->remoteMessageId ?? 'pending:'.Str::uuid(),
            'direction' => MessageDirection::Outbound,
            'author_remote_id' => $account->remote_account_id,
            'text' => $request->string('text')->toString(),
            'attachments' => [],
            'remote_created_at' => now(),
            'is_ours' => true,
            'send_status' => SendStatus::Sent,
            'our_remote_id' => $result->remoteMessageId,
        ]);

        return response()->json(['message' => MessageListItem::make($row)], 201);
    }
}
