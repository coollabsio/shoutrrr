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

#[Name('mark_replies_read')]
#[Description('Mark replies read. Pass reply_id to mark that reply\'s conversation read, or all=true to mark every unread inbox reply read (optionally narrowed by account_id, platform, or post_id).')]
class MarkRepliesReadTool extends EngagementTool
{
    public function handle(Request $request, ReplyInbox $inbox): Response
    {
        if ($error = $this->bindEngagement($request)) {
            return $error;
        }

        $validated = $request->validate([
            'reply_id' => ['required_without:all', 'nullable', 'string'],
            'all' => ['nullable', 'boolean'],
            'account_id' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
            'post_id' => ['nullable', 'string'],
        ]);

        if (isset($validated['reply_id'])) {
            $reply = $this->findReply($validated['reply_id']);

            if ($reply === null) {
                return $this->replyNotFound();
            }

            $inbox->markThreadRead($reply);

            return Response::text(json_encode(['reply_id' => $reply->id, 'read' => true], JSON_THROW_ON_ERROR));
        }

        if (($validated['all'] ?? false) !== true) {
            return Response::error('Pass reply_id, or all=true to mark every unread reply read.');
        }

        $marked = $inbox->markAllRead($inbox->filter(
            account: $validated['account_id'] ?? '',
            platform: $validated['platform'] ?? '',
            post: $validated['post_id'] ?? '',
        ));

        return Response::text(json_encode(['marked_read' => $marked], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply_id' => $schema->string()->description('Id of a reply whose conversation to mark read.'),
            'all' => $schema->boolean()->description('True to mark every unread inbox reply read.'),
            'account_id' => $schema->string()->description('With all=true, only replies to this connected account\'s posts.'),
            'platform' => $schema->string()->description('With all=true, only replies on this platform.'),
            'post_id' => $schema->string()->description('With all=true, only replies to this post.'),
        ];
    }
}
