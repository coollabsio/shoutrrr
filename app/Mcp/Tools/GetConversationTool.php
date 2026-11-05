<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\OpensInbox;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\DirectMessage;
use App\Support\ConversationListItem;
use App\Support\MessageListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_conversation')]
#[Description('Get one direct-message conversation and its full thread, oldest message first. Messages with is_ours=true were sent by the workspace. Does not mark the conversation read; use mark_conversation_read for that.')]
class GetConversationTool extends WorkspaceTool
{
    use OpensInbox;

    public function handle(Request $request): Response
    {
        $conversation = $this->openConversation($request);

        if ($conversation instanceof Response) {
            return $conversation;
        }

        $messages = $conversation->messages()
            ->with('media')
            ->orderBy('remote_created_at')
            ->get()
            ->map(fn (DirectMessage $message): array => MessageListItem::make($message))
            ->all();

        return Response::text(json_encode([
            'conversation' => ConversationListItem::make($conversation),
            'messages' => $messages,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()->description('Id of the conversation, from list_conversations.')->required(),
        ];
    }
}
