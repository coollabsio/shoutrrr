<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\WorkspaceMention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_mention')]
#[Description('Delete a saved mention from the bound workspace. Posts that already use it keep their text.')]
class DeleteMentionTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'mention_id' => ['required', 'string'],
        ]);

        $mention = WorkspaceMention::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($validated['mention_id'])
            ->first();

        if ($mention === null) {
            return Response::error('No saved mention with that id exists in this workspace.');
        }

        $mention->delete();

        return Response::text(json_encode(['deleted' => true, 'id' => $validated['mention_id']], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mention_id' => $schema->string()->description('Id of the saved mention, from list_mentions.')->required(),
        ];
    }
}
