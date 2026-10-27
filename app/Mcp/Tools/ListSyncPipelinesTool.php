<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsSyncPipeline;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\SyncPipeline;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_sync_pipelines')]
#[Description('List sync pipelines in the bound workspace: name, enabled flag, source account, and destination accounts.')]
class ListSyncPipelinesTool extends WorkspaceTool
{
    use PresentsSyncPipeline;

    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.settings.manage')) {
            return $denied;
        }

        $pipelines = SyncPipeline::query()
            ->with('destinations:id')
            ->latest()
            ->get()
            ->map(fn (SyncPipeline $pipeline): array => $this->pipelinePayload($pipeline))
            ->values();

        return Response::text(json_encode(['pipelines' => $pipelines], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
