<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Support\PostListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List posts in the bound workspace. Optionally filter by status or a text query.')]
class ListPostsTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,scheduled,publishing,published,partial,failed,deleted'],
            'q' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $posts = Post::query()
            ->with(['author:id,name', 'targets'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['q'] ?? null, fn ($query, $q) => $query->whereLike('base_text', "%{$q}%"))
            ->latest()
            ->limit($validated['limit'] ?? 20)
            ->get()
            ->map(fn (Post $post): array => PostListItem::make($post));

        return Response::text(json_encode(['posts' => $posts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['draft', 'scheduled', 'publishing', 'published', 'partial', 'failed', 'deleted'])
                ->description('Filter to one post status.'),
            'q' => $schema->string()->description('Substring match on post text.'),
            'limit' => $schema->integer()->description('Max posts to return (1-100, default 20).'),
        ];
    }
}
