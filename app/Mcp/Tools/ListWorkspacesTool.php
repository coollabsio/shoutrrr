<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_workspaces')]
#[Description('List the workspace this connection is bound to plus the users other workspaces. Reconnect to operate on a different workspace.')]
class ListWorkspacesTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        $boundId = $this->bindWorkspace($request);
        /** @var User $user */
        $user = $request->user();

        $workspaces = $user
            ->workspaceMemberships()->with('workspace')->get()
            ->pluck('workspace')->filter()->values()
            ->map(fn ($ws): array => [
                'id' => $ws->id,
                'name' => $ws->name,
                'bound' => $ws->id === $boundId,
            ]);

        return Response::text(json_encode([
            'bound_workspace_id' => $boundId,
            'workspaces' => $workspaces,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
