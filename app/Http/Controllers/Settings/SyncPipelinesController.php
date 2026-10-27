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
use App\Services\Sync\SyncPipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SyncPipelinesController extends Controller
{
    public function __construct(
        private readonly WorkspaceSubscriptionGate $gate,
        private readonly SyncPipelineService $pipelines,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        $accounts = ConnectedAccount::query()
            ->where('workspace_id', $workspace->id)
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status->value,
                'enabled' => ! $account->isDisabled(),
                'supports_native' => $account->platform->supportsNativeRead(),
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

        return Inertia::render('sync', [
            'accounts' => $accounts,
            'selectableAccounts' => $accounts->where('enabled', true)->values(),
            'pipelines' => $pipelines,
            'maxPipelines' => (int) config('subscriptions.max_sync_pipelines'),
            'canCreate' => $this->gate->canCreateSyncPipeline($workspace),
            'trackableAccounts' => $accounts->filter(fn (array $a): bool => $a['enabled'] && Platform::from($a['platform'])->supportsNativeRead())->values(),
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

        /** @var array{name: string, enabled?: bool, source_connected_account_id: string, destination_connected_account_ids: list<string>, track_source?: bool} $validated */
        $validated = $request->validate($this->pipelines->createRules($workspace->id));

        $result = $this->pipelines->create($workspace, $user, $validated);

        return back()->with('success', $result['tracked_source']
            ? 'Sync pipeline created and now tracking the source account.'
            : 'Sync pipeline created.');
    }

    public function update(Request $request, SyncPipeline $syncPipeline): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($syncPipeline->workspace_id === $user->current_workspace_id, 404);
        $this->authorizeManage($user, $syncPipeline->workspace_id);

        /** @var array{name?: string, enabled?: bool, source_connected_account_id?: string, destination_connected_account_ids?: list<string>} $validated */
        $validated = $request->validate($this->pipelines->updateRules($syncPipeline->workspace_id));

        $this->pipelines->update($syncPipeline, $validated);

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

    private function authorizeManage(User $user, string $workspaceId): void
    {
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403);
    }
}
