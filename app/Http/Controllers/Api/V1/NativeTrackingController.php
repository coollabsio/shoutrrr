<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\NativeTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Native tracking watches an account for posts published outside Shoutrrr so
 * sync pipelines can copy them too. Needs the workspace.settings.manage
 * permission.
 */
class NativeTrackingController extends Controller
{
    public function __construct(private readonly NativeTracking $tracking) {}

    /**
     * Fails with 422 when the platform cannot be tracked or the plan's tracked
     * account limit is reached. Tracking an already tracked account succeeds.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $user = $this->authorizeManage($request);
        $account = $this->findAccountOrFail($id);

        $this->tracking->track(Workspace::query()->findOrFail($account->workspace_id), $user, $account);

        return response()->json(['id' => $account->id, 'native_tracking' => true]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeManage($request);
        $account = $this->findAccountOrFail($id);

        $this->tracking->untrack($account);

        return response()->json(['id' => $account->id, 'native_tracking' => false]);
    }

    private function findAccountOrFail(string $id): ConnectedAccount
    {
        return ConnectedAccount::query()->whereKey($id)
            ->firstOr(fn () => abort(404, 'No connected account with that id exists in this workspace.'));
    }

    private function authorizeManage(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], Context::get('workspace_id')), 403, 'This action is unauthorized.');

        return $user;
    }
}
