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

#[Name('like_reply')]
#[Description('Like (liked=true) or unlike (liked=false) an inbound reply on its platform. A no-op when the reply is already in that state.')]
class LikeReplyTool extends EngagementTool
{
    public function handle(Request $request, ReplyInbox $inbox): Response
    {
        if ($error = $this->bindEngagement($request)) {
            return $error;
        }

        $validated = $request->validate([
            'reply_id' => ['required', 'string'],
            'liked' => ['required', 'boolean'],
        ]);

        $reply = $this->findReply($validated['reply_id']);

        if ($reply === null) {
            return $this->replyNotFound();
        }

        $liked = (bool) $validated['liked'];
        $result = $inbox->setLiked($reply, $liked);

        if (! $result->isOk()) {
            return $this->actionFailed($result->message, $liked ? 'Could not like this reply.' : 'Could not remove the like.');
        }

        return Response::text(json_encode(['reply_id' => $reply->id, 'is_liked' => $liked], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply_id' => $schema->string()->description('Id of the reply.')->required(),
            'liked' => $schema->boolean()->description('True to like, false to remove the like.')->required(),
        ];
    }
}
