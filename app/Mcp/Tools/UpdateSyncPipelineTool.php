<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsSyncPipeline;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\SyncPipeline;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_sync_pipeline')]
#[Description('Update a sync pipeline name, enabled flag, source account, or destination accounts. The source cannot also be a destination.')]
class UpdateSyncPipelineTool extends WorkspaceTool
{
    use PresentsSyncPipeline;

    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);

        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.settings.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'pipeline_id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'source_connected_account_id' => ['sometimes', 'string', $this->accountRule($workspaceId)],
            'destination_connected_account_ids' => ['sometimes', 'array', 'min:1', 'max:3'],
            'destination_connected_account_ids.*' => [$this->accountRule($workspaceId)],
        ]);

        $pipeline = SyncPipeline::query()->whereKey($validated['pipeline_id'])->first();

        if ($pipeline === null) {
            return Response::error('No sync pipeline with that id exists in this workspace.');
        }

        $finalSource = $validated['source_connected_account_id'] ?? $pipeline->source_connected_account_id;
        $finalDestinations = array_key_exists('destination_connected_account_ids', $validated)
            ? $validated['destination_connected_account_ids']
            : $pipeline->destinations()->pluck('connected_accounts.id')->all();

        if (in_array((string) $finalSource, array_map(static fn (mixed $id): string => (string) $id, $finalDestinations), true)) {
            return Response::error('The source account cannot also be a destination.');
        }

        $pipeline->update(array_intersect_key($validated, array_flip(['name', 'enabled', 'source_connected_account_id'])));

        if (array_key_exists('destination_connected_account_ids', $validated)) {
            $pipeline->destinations()->sync($validated['destination_connected_account_ids']);
        }

        return Response::text(json_encode([
            'pipeline' => $this->pipelinePayload($pipeline->refresh()),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function accountRule(string $workspaceId): Exists
    {
        return Rule::exists('connected_accounts', 'id')->where('workspace_id', $workspaceId);
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
