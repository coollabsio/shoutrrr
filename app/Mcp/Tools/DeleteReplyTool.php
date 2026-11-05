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

#[Name('delete_reply')]
#[Description('Delete one of our own replies (is_ours=true) from its platform and from Shoutrrr. Inbound replies from other people cannot be deleted. Irreversible. Requires confirm=true.')]
class DeleteReplyTool extends EngagementTool
{
    public function handle(Request $request, ReplyInbox $inbox): Response
    {
        if ($error = $this->bindEngagement($request)) {
            return $error;
        }

        $validated = $request->validate([
            'reply_id' => ['required', 'string'],
            'confirm' => ['boolean'],
        ]);

        $reply = $this->findReply($validated['reply_id']);

        if ($reply === null) {
            return $this->replyNotFound();
        }

        if (! $reply->is_ours) {
            return Response::error('Only replies sent from this workspace can be deleted.');
        }

        if ($unconfirmed = $this->requireConfirmation($request, 'This will delete the reply from '.$reply->platform->value.'.')) {
            return $unconfirmed;
        }

        $result = $inbox->deleteOurReply($reply);

        if (! $result->isOk()) {
            return $this->actionFailed($result->message, 'Could not delete this reply.');
        }

        return Response::text(json_encode(['reply_id' => $reply->id, 'deleted' => true], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply_id' => $schema->string()->description('Id of our reply to delete.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to delete.'),
        ];
    }
}
