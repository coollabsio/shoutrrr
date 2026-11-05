<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\SyncPipeline;
use App\Services\Sync\SyncPipelineService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_sync_pipeline')]
#[Description('Update a sync pipeline name, enabled flag, source account, or destination accounts. The source cannot also be a destination.')]
class UpdateSyncPipelineTool extends WorkspaceTool
{
    public function handle(Request $request, SyncPipelineService $pipelines): Response
    {
        $workspaceId = $this->bindWorkspace($request);

        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.settings.manage')) {
            return $denied;
        }

        /** @var array{pipeline_id: string, name?: string, enabled?: bool, source_connected_account_id?: string, destination_connected_account_ids?: list<string>} $validated */
        $validated = $request->validate([
            'pipeline_id' => ['required', 'string'],
            ...$pipelines->updateRules($workspaceId),
        ]);

        $pipeline = SyncPipeline::query()->whereKey($validated['pipeline_id'])->first();

        if ($pipeline === null) {
            return Response::error('No sync pipeline with that id exists in this workspace.');
        }

        unset($validated['pipeline_id']);

        return Response::text(json_encode([
            'pipeline' => $pipelines->payload($pipelines->update($pipeline, $validated)),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pipeline_id' => $schema->string()->description('Id of the sync pipeline.')->required(),
            'name' => $schema->string()->description('New name.'),
            'enabled' => $schema->boolean()->description('Whether the pipeline is enabled.'),
            'source_connected_account_id' => $schema->string()->description('Replacement source account id.'),
            'destination_connected_account_ids' => $schema->array()->description('Replacement destination account ids (one to three).'),
        ];
    }
}
