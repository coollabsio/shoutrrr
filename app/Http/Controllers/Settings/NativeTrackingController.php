<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\Sync\NativeTracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NativeTrackingController extends Controller
{
    public function __construct(private readonly NativeTracking $tracking) {}

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

        $this->tracking->track($workspace, $user, $account);

        return back()->with('success', 'Native tracking enabled.');
    }

    public function destroy(Request $request, ConnectedAccount $account): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        $this->tracking->untrack($account);

        return back()->with('success', 'Native tracking disabled.');
    }

    private function authorizeManage(User $user, string $workspaceId): void
    {
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403);
    }
}
