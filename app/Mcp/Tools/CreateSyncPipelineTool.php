<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsSyncPipeline;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_sync_pipeline')]
#[Description('Create a sync pipeline that reposts from one connected account to up to three others. The source cannot also be a destination. Optional track_source turns on native tracking for the source when the plan allows it.')]
class CreateSyncPipelineTool extends WorkspaceTool
{
    use PresentsSyncPipeline;

    public function handle(Request $request, WorkspaceSubscriptionGate $gate): Response
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

        if (! $gate->canCreateSyncPipeline($workspace)) {
            $max = (int) config('subscriptions.max_sync_pipelines');

            return Response::error("You've reached your plan's limit of {$max} sync pipelines. Delete one to create another.");
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'source_connected_account_id' => ['required', 'string', $this->accountRule($workspace->id)],
            'destination_connected_account_ids' => ['required', 'array', 'min:1', 'max:3'],
            'destination_connected_account_ids.*' => [$this->accountRule($workspace->id), 'different:source_connected_account_id'],
            'track_source' => ['sometimes', 'boolean'],
        ]);

        if ($this->sourceIsDestination($validated['source_connected_account_id'], $validated['destination_connected_account_ids'])) {
            return Response::error('The source account cannot also be a destination.');
        }

        $pipeline = SyncPipeline::query()->create([
            'workspace_id' => $workspace->id,
            'source_connected_account_id' => $validated['source_connected_account_id'],
            'name' => $validated['name'],
            'enabled' => $validated['enabled'] ?? true,
            'created_by' => $user->id,
        ]);
        $pipeline->destinations()->sync($validated['destination_connected_account_ids']);

        $tracked = $this->maybeTrackSource(
            $workspace,
            $user,
            $validated['source_connected_account_id'],
            (bool) ($validated['track_source'] ?? false),
            $gate,
        );

        return Response::text(json_encode([
            'pipeline' => $this->pipelinePayload($pipeline),
            'tracked_source' => $tracked,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<mixed>  $destinations
     */
    private function sourceIsDestination(string $source, array $destinations): bool
    {
        return in_array($source, array_map(static fn (mixed $id): string => (string) $id, $destinations), true);
    }

    private function accountRule(string $workspaceId): Exists
    {
        return Rule::exists('connected_accounts', 'id')->where('workspace_id', $workspaceId);
    }

    private function maybeTrackSource(Workspace $workspace, User $user, string $sourceId, bool $optedIn, WorkspaceSubscriptionGate $gate): bool
    {
        if (! $optedIn) {
            return false;
        }

        $source = ConnectedAccount::query()->where('workspace_id', $workspace->id)->whereKey($sourceId)->first();

        if ($source === null || ! $source->platform->supportsNativeRead()) {
            return false;
        }

        if (! $source->nativeWatch()->exists() && ! $gate->canTrackNativeAccount($workspace)) {
            return false;
        }

        ConnectedAccountNativeWatch::query()->firstOrCreate(
            ['connected_account_id' => $source->id],
            ['workspace_id' => $workspace->id, 'enabled_at' => now(), 'enabled_by' => $user->id],
        );

        return true;
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
