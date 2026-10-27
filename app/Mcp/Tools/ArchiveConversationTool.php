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

#[Name('archive_conversation')]
#[Description('Archive a direct-message conversation so it leaves the inbox. Local only; nothing is sent to the platform. List archived conversations with list_conversations archived=true.')]
class ArchiveConversationTool extends WorkspaceTool
{
    use OpensInbox;

    public function handle(Request $request): Response
    {
        $conversation = $this->openConversation($request);

        if ($conversation instanceof Response) {
            return $conversation;
        }

        $conversation->forceFill(['archived_at' => now()])->save();

        return Response::text(json_encode([
            'conversation_id' => $conversation->id,
            'archived' => true,
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
