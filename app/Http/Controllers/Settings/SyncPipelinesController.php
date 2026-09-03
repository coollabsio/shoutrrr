<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Services\Billing\WorkspaceSubscriptionGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SyncPipelinesController extends Controller
{
    public function __construct(private readonly WorkspaceSubscriptionGate $gate) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        $accounts = ConnectedAccount::query()
            ->where('workspace_id', $workspace->id)
            ->enabled()
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status->value,
            ])->values();

        $pipelines = SyncPipeline::query()
            ->where('workspace_id', $workspace->id)
            ->with('destinations:id')
            ->latest()
            ->get()
            ->map(fn (SyncPipeline $pipeline): array => [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'enabled' => $pipeline->enabled,
                'source_connected_account_id' => $pipeline->source_connected_account_id,
                'destination_connected_account_ids' => $pipeline->destinations->pluck('id')->all(),
            ]);

        return Inertia::render('settings/workspace/sync-pipelines', [
            'accounts' => $accounts,
            'pipelines' => $pipelines,
            'maxPipelines' => (int) config('subscriptions.max_sync_pipelines'),
            'canCreate' => $this->gate->canCreateSyncPipeline($workspace),
            'trackableAccounts' => $accounts->filter(fn (array $a): bool => Platform::from($a['platform'])->supportsNativeRead())->values(),
            'trackedAccountIds' => ConnectedAccountNativeWatch::query()
                ->where('workspace_id', $workspace->id)->pluck('connected_account_id'),
            'canTrack' => $this->gate->canTrackNativeAccount($workspace),
            'maxTracked' => (int) config('subscriptions.max_native_tracked'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        if (! $this->gate->canCreateSyncPipeline($workspace)) {
            throw ValidationException::withMessages([
                'name' => 'You have reached your plan\'s sync pipeline limit.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'source_connected_account_id' => ['required', 'string', $this->accountRule($workspace->id)],
            'destination_connected_account_ids' => ['required', 'array', 'min:1', 'max:3'],
            'destination_connected_account_ids.*' => [$this->accountRule($workspace->id), 'different:source_connected_account_id'],
        ]);

        $this->assertSourceNotDestination($validated['source_connected_account_id'], $validated['destination_connected_account_ids']);

        $pipeline = SyncPipeline::create([
            'workspace_id' => $workspace->id,
            'source_connected_account_id' => $validated['source_connected_account_id'],
            'name' => $validated['name'],
            'enabled' => $validated['enabled'] ?? true,
            'created_by' => $user->id,
        ]);
        $pipeline->destinations()->sync($validated['destination_connected_account_ids']);

        return back()->with('success', 'Sync pipeline created.');
    }

    public function update(Request $request, SyncPipeline $syncPipeline): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($syncPipeline->workspace_id === $user->current_workspace_id, 404);
        $this->authorizeManage($user, $syncPipeline->workspace_id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'source_connected_account_id' => ['sometimes', 'string', $this->accountRule($syncPipeline->workspace_id)],
            'destination_connected_account_ids' => ['sometimes', 'array', 'min:1', 'max:3'],
            'destination_connected_account_ids.*' => [$this->accountRule($syncPipeline->workspace_id)],
        ]);

        // Enforce the source∉destinations invariant against the *final* config,
        // since either field may be omitted and retain its stored value.
        $finalSource = $validated['source_connected_account_id'] ?? $syncPipeline->source_connected_account_id;
        $finalDestinations = array_key_exists('destination_connected_account_ids', $validated)
            ? $validated['destination_connected_account_ids']
            : $syncPipeline->destinations()->pluck('connected_accounts.id')->all();
        $this->assertSourceNotDestination((string) $finalSource, $finalDestinations);

        $syncPipeline->update(array_intersect_key($validated, array_flip(['name', 'enabled', 'source_connected_account_id'])));
        if (array_key_exists('destination_connected_account_ids', $validated)) {
            $syncPipeline->destinations()->sync($validated['destination_connected_account_ids']);
        }

        return back()->with('success', 'Sync pipeline updated.');
    }

    public function destroy(Request $request, SyncPipeline $syncPipeline): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($syncPipeline->workspace_id === $user->current_workspace_id, 404);
        $this->authorizeManage($user, $syncPipeline->workspace_id);

        $syncPipeline->delete();

        return back()->with('success', 'Sync pipeline deleted.');
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

    private function authorizeManage(User $user, string $workspaceId): void
    {
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403);
    }
}
