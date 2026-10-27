<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\OpensInbox;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Conversation;
use App\Support\ConversationListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_conversations')]
#[Description('List direct-message conversations in the bound workspace, most recent first. Shows the inbox by default; pass archived=true for archived conversations. Each entry has unread_count and can_reply (false once a platform reply window has closed).')]
class ListConversationsTool extends WorkspaceTool
{
    use OpensInbox;

    public function handle(Request $request): Response
    {
        if ($closed = $this->openInbox($request)) {
            return $closed;
        }

        $validated = $request->validate([
            'archived' => ['boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $conversations = Conversation::query()
            ->when(
                $validated['archived'] ?? false,
                fn (Builder $query) => $query->whereNotNull('archived_at'),
                fn (Builder $query) => $query->whereNull('archived_at'),
            )
            ->with('account')
            ->orderByDesc('last_message_at')
            ->limit($validated['limit'] ?? 30)
            ->get()
            ->map(fn (Conversation $conversation): array => ConversationListItem::make($conversation))
            ->all();

        return Response::text(json_encode(['conversations' => $conversations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'archived' => $schema->boolean()->description('True to list archived conversations instead of the inbox (default false).'),
            'limit' => $schema->integer()->description('Max conversations to return (1-100, default 30).'),
        ];
    }
}
