<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EngagementTool;
use App\Models\PostTargetReply;
use App\Services\Engagement\ReplyInbox;
use App\Support\ReplyListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_reply_thread')]
#[Description('Get the full conversation a reply belongs to, oldest first, including replies we sent (is_ours). Opening a thread marks its inbound replies read, as in the inbox.')]
class GetReplyThreadTool extends EngagementTool
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

        return Response::text(json_encode([
            'post_id' => $reply->target?->post_id,
            'post_excerpt' => $reply->target?->post?->excerpt(),
            'thread' => $inbox->openThread($reply)
                ->map(fn (PostTargetReply $r): array => ReplyListItem::make($r))
                ->all(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
