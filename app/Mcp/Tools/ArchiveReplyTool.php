<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EngagementTool;
use App\Services\Engagement\ReplyInbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('archive_reply')]
#[Description('Archive the conversation a reply belongs to, moving its inbound replies out of the live inbox. Local only; nothing changes on the platform. List archived ones with list_replies archived=true.')]
class ArchiveReplyTool extends EngagementTool
{
    public function handle(Request $request, ReplyInbox $inbox): Response
    {
        if ($error = $this->bindEngagement($request)) {
            return $error;
        }

        $validated = $request->validate(['reply_id' => ['required', 'string']]);

        $reply = $this->findReply($validated['reply_id']);

        if ($reply === null) {
            return $this->replyNotFound();
        }

        $inbox->archiveThread($reply);

        return Response::text(json_encode(['reply_id' => $reply->id, 'archived' => true], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply_id' => $schema->string()->description('Id of any reply in the conversation.')->required(),
        ];
    }
}
