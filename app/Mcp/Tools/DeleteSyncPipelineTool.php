<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\SyncPipeline;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_sync_pipeline')]
#[Description('Delete a sync pipeline. It stops reposting. Irreversible. Requires confirm=true.')]
class DeleteSyncPipelineTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.settings.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'pipeline_id' => ['required', 'string'],
            'confirm' => ['boolean'],
        ]);

        $pipeline = SyncPipeline::query()->whereKey($validated['pipeline_id'])->first();

        if ($pipeline === null) {
            return Response::error('No sync pipeline with that id exists in this workspace.');
        }

        if ($unconfirmed = $this->requireConfirmation($request, 'This will delete the sync pipeline "'.$pipeline->name.'" and stop its reposts.')) {
            return $unconfirmed;
        }

        $pipeline->delete();

        return Response::text(json_encode(['deleted' => true, 'pipeline_id' => $validated['pipeline_id']], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pipeline_id' => $schema->string()->description('Id of the sync pipeline to delete.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to delete.'),
        ];
    }
}
