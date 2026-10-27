<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\OpensInbox;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('mark_conversation_read')]
#[Description('Mark a direct-message conversation as read in Shoutrrr, clearing its unread count. Local only; nothing is sent to the platform.')]
class MarkConversationReadTool extends WorkspaceTool
{
    use OpensInbox;

    public function handle(Request $request): Response
    {
        $conversation = $this->openConversation($request);

        if ($conversation instanceof Response) {
            return $conversation;
        }

        $conversation->forceFill(['read_at' => now(), 'unread_count' => 0])->save();

        return Response::text(json_encode([
            'conversation_id' => $conversation->id,
            'unread_count' => 0,
        ], JSON_THROW_ON_ERROR));
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
