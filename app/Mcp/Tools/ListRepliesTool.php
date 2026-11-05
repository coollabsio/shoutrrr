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

#[Name('list_replies')]
#[Description('List the engagement inbox: one row per conversation of inbound replies to published posts, newest first, with the latest reply and its reply/unread counts. Use a row id with get_reply_thread, respond_to_reply, like_reply, archive_reply, or mark_replies_read.')]
class ListRepliesTool extends EngagementTool
{
    public function handle(Request $request, ReplyInbox $inbox): Response
    {
        if ($error = $this->bindEngagement($request)) {
            return $error;
        }

        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
            'account_id' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
            'post_id' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $conversations = $inbox->conversations(
            $inbox->filter(
                account: $validated['account_id'] ?? '',
                platform: $validated['platform'] ?? '',
                post: $validated['post_id'] ?? '',
                unread: (bool) ($validated['unread'] ?? false),
                archived: (bool) ($validated['archived'] ?? false),
            ),
            $validated['limit'] ?? 25,
            $validated['page'] ?? 1,
        );

        return Response::text(json_encode([
            'conversations' => $conversations->items(),
            'page' => $conversations->currentPage(),
            'has_more' => $conversations->hasMorePages(),
            'total' => $conversations->total(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'unread' => $schema->boolean()->description('Only conversations with unread replies.'),
            'archived' => $schema->boolean()->description('List archived conversations instead of the live inbox.'),
            'account_id' => $schema->string()->description('Only replies to posts from this connected account.'),
            'platform' => $schema->string()->description('Only replies on this platform, e.g. x, bluesky, linkedin.'),
            'post_id' => $schema->string()->description('Only replies to this post.'),
            'limit' => $schema->integer()->description('Conversations per page (1-50, default 25).'),
            'page' => $schema->integer()->description('Page number, starting at 1.'),
        ];
    }
}
