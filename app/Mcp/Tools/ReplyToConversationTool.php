<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Dto\Messaging\MessageSendResult;
use App\Http\Requests\Messaging\RespondToMessageRequest;
use App\Mcp\Tools\Concerns\OpensInbox;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Services\Messaging\ConversationReplier;
use App\Support\MessageListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('reply_to_conversation')]
#[Description('Send a direct message to the other person in a conversation, from the connected account that owns it. The recipient sees it immediately and it cannot be unsent. Requires confirm=true. Fails when can_reply is false (the platform reply window has closed).')]
class ReplyToConversationTool extends WorkspaceTool
{
    use OpensInbox;

    public function handle(Request $request, ConversationReplier $replier): Response
    {
        $conversation = $this->openConversation($request);

        if ($conversation instanceof Response) {
            return $conversation;
        }

        $validated = $request->validate([
            ...RespondToMessageRequest::rulesFor($conversation),
            'confirm' => ['boolean'],
        ]);

        $recipient = $conversation->counterpart_handle ?? $conversation->counterpart_name ?? 'the other person';

        if ($unconfirmed = $this->requireConfirmation($request, "This will send a direct message to {$recipient}.")) {
            return $unconfirmed;
        }

        /** @var list<string> $mediaIds */
        $mediaIds = $validated['media'] ?? [];
        $outcome = $replier->reply($conversation, (string) ($validated['text'] ?? ''), $mediaIds);

        if ($outcome instanceof MessageSendResult) {
            return Response::error(json_encode([
                'status' => $outcome->status->value,
                'message' => $outcome->excerpt,
            ], JSON_THROW_ON_ERROR));
        }

        return Response::text(json_encode(['message' => MessageListItem::make($outcome)], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()->description('Id of the conversation, from list_conversations.')->required(),
            'text' => $schema->string()->description('Message text, up to 1000 characters. Required unless media is given.'),
            'media' => $schema->array()->items($schema->string())->description('Ids of already-uploaded, unattached direct-message media to send. At most one, and not supported on Bluesky.'),
            'confirm' => $schema->boolean()->description('Must be true to send.'),
        ];
    }
}
