<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\SyncPipelineService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_sync_pipeline')]
#[Description('Create a sync pipeline that reposts from one connected account to up to three others. The source cannot also be a destination. Optional track_source turns on native tracking for the source when the plan allows it.')]
class CreateSyncPipelineTool extends WorkspaceTool
{
    public function handle(Request $request, SyncPipelineService $pipelines): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.settings.manage')) {
            return $denied;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $workspace = $user->currentWorkspace()->first();

        if (! $workspace instanceof Workspace) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        /** @var array{name: string, enabled?: bool, source_connected_account_id: string, destination_connected_account_ids: list<string>, track_source?: bool} $validated */
        $validated = $request->validate($pipelines->createRules($workspace->id));

        $result = $pipelines->create($workspace, $user, $validated);

        return Response::text(json_encode([
            'pipeline' => $pipelines->payload($result['pipeline']),
            'tracked_source' => $result['tracked_source'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name of the pipeline.')->required(),
            'source_connected_account_id' => $schema->string()->description('Account whose posts are copied.')->required(),
            'destination_connected_account_ids' => $schema->array()->description('One to three destination account ids. Must not include the source.')->required(),
            'enabled' => $schema->boolean()->description('Whether the pipeline starts enabled. Defaults to true.'),
            'track_source' => $schema->boolean()->description('Also track posts the source publishes outside Shoutrrr, when that platform supports it.'),
        ];
    }
}
