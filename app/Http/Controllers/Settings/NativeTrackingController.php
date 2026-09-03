<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\User;
use App\Services\Billing\WorkspaceSubscriptionGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NativeTrackingController extends Controller
{
    public function __construct(private readonly WorkspaceSubscriptionGate $gate) {}

    /**
     * The {account} route parameter is resolved by the global `Route::bind('account', ...)`
     * in routes/accounts.php, which already scopes the lookup to the authenticated user's
     * current workspace and 404s on a foreign or missing id.
     */
    public function store(Request $request, ConnectedAccount $account): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        if (! $account->platform->supportsNativeRead()) {
            throw ValidationException::withMessages(['account' => 'This platform does not support native tracking.']);
        }
        if (! $account->nativeWatch()->exists() && ! $this->gate->canTrackNativeAccount($workspace)) {
            throw ValidationException::withMessages(['account' => 'You have reached your plan\'s native tracking limit.']);
        }

        ConnectedAccountNativeWatch::firstOrCreate(
            ['connected_account_id' => $account->id],
            ['workspace_id' => $workspace->id, 'enabled_at' => now(), 'enabled_by' => $user->id],
        );

        return back()->with('success', 'Native tracking enabled.');
    }

    public function destroy(Request $request, ConnectedAccount $account): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        ConnectedAccountNativeWatch::query()
            ->where('workspace_id', $workspace->id)
            ->where('connected_account_id', $account->id)
            ->delete();

        return back()->with('success', 'Native tracking disabled.');
    }

    private function authorizeManage(User $user, string $workspaceId): void
    {
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403);
    }
}
