<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\WorkspaceMention;
use App\Services\Workspace\WorkspaceMentionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_mentions')]
#[Description('List the saved mentions in the bound workspace: a name like @acme with a handle per platform. Each entry includes `post_mention`, which can be passed as-is in the `mentions` array of create_post or update_post; reference it in the text as {{mention:<post_mention.id>}}.')]
class ListMentionsTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $mentions = WorkspaceMention::query()
            ->orderBy('name')
            ->get()
            ->map(fn (WorkspaceMention $mention): array => WorkspaceMentionService::present($mention))
            ->all();

        return Response::text(json_encode(['mentions' => $mentions], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
