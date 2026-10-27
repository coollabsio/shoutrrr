<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\ConnectedAccount;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Sync pipeline writes shared by the REST API and the MCP tools. Callers
 * validate input with createRules()/updateRules() and check the
 * workspace.settings.manage permission; this class enforces the plan cap and
 * the source-is-not-a-destination invariant.
 */
class SyncPipelineService
{
    public function __construct(
        private readonly WorkspaceSubscriptionGate $gate,
        private readonly NativeTracking $tracking,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createRules(string $workspaceId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'source_connected_account_id' => ['required', 'string', $this->accountRule($workspaceId)],
            'destination_connected_account_ids' => ['required', 'array', 'min:1', 'max:3'],
            'destination_connected_account_ids.*' => ['string', $this->accountRule($workspaceId)],
            'track_source' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateRules(string $workspaceId): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'source_connected_account_id' => ['sometimes', 'string', $this->accountRule($workspaceId)],
            'destination_connected_account_ids' => ['sometimes', 'array', 'min:1', 'max:3'],
            'destination_connected_account_ids.*' => ['string', $this->accountRule($workspaceId)],
        ];
    }

    /**
     * @param  array{name: string, enabled?: bool, source_connected_account_id: string, destination_connected_account_ids: list<string>, track_source?: bool}  $validated
     * @return array{pipeline: SyncPipeline, tracked_source: bool}
     *
     * @throws ValidationException when the plan cap is reached or the source is also a destination.
     */
    public function create(Workspace $workspace, User $user, array $validated): array
    {
        if (! $this->gate->canCreateSyncPipeline($workspace)) {
            $max = (int) config('subscriptions.max_sync_pipelines');

            throw ValidationException::withMessages([
                'name' => "You've reached your plan's limit of {$max} sync pipelines. Delete one to create another.",
            ]);
        }

        $this->assertSourceNotDestination($validated['source_connected_account_id'], $validated['destination_connected_account_ids']);

        $pipeline = DB::transaction(function () use ($workspace, $user, $validated): SyncPipeline {
            $pipeline = SyncPipeline::query()->create([
                'workspace_id' => $workspace->id,
                'source_connected_account_id' => $validated['source_connected_account_id'],
                'name' => $validated['name'],
                'enabled' => $validated['enabled'] ?? true,
                'created_by' => $user->id,
            ]);
            $pipeline->destinations()->sync($validated['destination_connected_account_ids']);

            return $pipeline;
        });

        $trackedSource = false;

        if ($validated['track_source'] ?? false) {
            $source = ConnectedAccount::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($validated['source_connected_account_id'])
                ->firstOrFail();
            $trackedSource = $this->tracking->trackIfEligible($workspace, $user, $source);
        }

        return ['pipeline' => $pipeline, 'tracked_source' => $trackedSource];
    }

    /**
     * Either field may be omitted and keep its stored value, so the invariant is
     * checked against the final configuration.
     *
     * @param  array{name?: string, enabled?: bool, source_connected_account_id?: string, destination_connected_account_ids?: list<string>}  $validated
     *
     * @throws ValidationException when the source would also be a destination.
     */
    public function update(SyncPipeline $pipeline, array $validated): SyncPipeline
    {
        $finalSource = $validated['source_connected_account_id'] ?? $pipeline->source_connected_account_id;
        $finalDestinations = $validated['destination_connected_account_ids']
            ?? $pipeline->destinations()->pluck('connected_accounts.id')->all();

        $this->assertSourceNotDestination((string) $finalSource, $finalDestinations);

        DB::transaction(function () use ($pipeline, $validated): void {
            $pipeline->update(array_intersect_key($validated, array_flip(['name', 'enabled', 'source_connected_account_id'])));

            if (array_key_exists('destination_connected_account_ids', $validated)) {
                $pipeline->destinations()->sync($validated['destination_connected_account_ids']);
            }
        });

        return $pipeline->refresh();
    }

    /**
     * @return array{id: string, name: string, enabled: bool, source_connected_account_id: string, destination_connected_account_ids: list<string>}
     */
    public function payload(SyncPipeline $pipeline): array
    {
        $pipeline->loadMissing('destinations:id');

        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'enabled' => $pipeline->enabled,
            'source_connected_account_id' => $pipeline->source_connected_account_id,
            'destination_connected_account_ids' => array_values(
                $pipeline->destinations->pluck('id')->map(fn (mixed $id): string => (string) $id)->all()
            ),
        ];
    }

    /**
     * @param  array<int, mixed>  $destinations
     */
    private function assertSourceNotDestination(string $source, array $destinations): void
    {
        if (in_array($source, array_map(static fn (mixed $id): string => (string) $id, $destinations), true)) {
            throw ValidationException::withMessages([
                'destination_connected_account_ids' => 'The source account cannot also be a destination.',
            ]);
        }
    }

    private function accountRule(string $workspaceId): Exists
    {
        return Rule::exists('connected_accounts', 'id')->where('workspace_id', $workspaceId);
    }
}
