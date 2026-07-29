<?php

declare(strict_types=1);

namespace App\Http\Controllers\Messaging;

use App\Enums\EngagementStatus;
use App\Enums\MessageDirection;
use App\Enums\SendStatus;
use App\Http\Requests\RespondToMessageRequest;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\PostMedia;
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

        $text = $request->string('text')->toString();
        $media = $this->orderedMedia($request, $conversation);

        $account = $conversation->account;
        $credentials = $tokens->fresh($account);
        $result = $registry->for($conversation->platform)->sendMessage($account, $conversation, $text, $credentials, $media);

        if (! $result->isOk()) {
            return response()->json(['status' => $result->status->value, 'message' => $result->excerpt], $result->status->httpStatus());
        }

        $row = DirectMessage::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'remote_message_id' => $result->remoteMessageId ?? 'pending:'.Str::uuid(),
            'direction' => MessageDirection::Outbound,
            'author_remote_id' => $account->remote_account_id,
            'text' => $text,
            'attachments' => array_map($this->attachmentView(...), $media),
            'remote_created_at' => now(),
            'is_ours' => true,
            'send_status' => SendStatus::Sent,
            'our_remote_id' => $result->remoteMessageId,
        ]);

        return response()->json(['message' => MessageListItem::make($row)], 201);
    }

    /**
     * The picked attachments, resorted into the order the client listed them —
     * `whereIn` returns database order.
     *
     * @return list<PostMedia>
     */
    private function orderedMedia(RespondToMessageRequest $request, Conversation $conversation): array
    {
        /** @var list<string> $ids */
        $ids = $request->validated('media', []);

        if ($ids === []) {
            return [];
        }

        $rows = PostMedia::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return array_values(array_filter(array_map(
            fn (string $id): ?PostMedia => $rows->get($id),
            $ids,
        )));
    }

    /**
     * A self-contained render record, not `PostMedia::toView()`: DM media rows
     * are orphaned and prunable, but a sent message must keep rendering.
     *
     * @return array{kind: string, url: string, mime: string, alt_text: string|null}
     */
    private function attachmentView(PostMedia $media): array
    {
        return [
            'kind' => $media->kind,
            'url' => $media->url(),
            'mime' => $media->mime,
            'alt_text' => $media->alt_text,
        ];
    }
}
