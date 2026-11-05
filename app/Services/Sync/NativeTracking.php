<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Native tracking watches a connected account for posts published outside
 * Shoutrrr so sync pipelines can fan them out. Gated by platform support and
 * the plan's tracked-account cap.
 */
class NativeTracking
{
    public function __construct(private readonly WorkspaceSubscriptionGate $gate) {}

    /**
     * @throws ValidationException when the platform cannot be tracked or the plan cap is reached.
     */
    public function track(Workspace $workspace, User $user, ConnectedAccount $account): void
    {
        if (! $account->platform->supportsNativeRead()) {
            throw ValidationException::withMessages([
                'account' => ucfirst($account->platform->value).' does not support native tracking, so its posts can only sync when published through Shoutrrr.',
            ]);
        }

        if (! $this->watchWithinCap($workspace, $user, $account)) {
            $max = (int) config('subscriptions.max_native_tracked');

            throw ValidationException::withMessages([
                'account' => "You've reached your plan's limit of {$max} tracked accounts. Untrack one to track another.",
            ]);
        }
    }

    /**
     * Best-effort variant used when a pipeline opts its source into tracking:
     * never throws, returns whether the account is now tracked.
     */
    public function trackIfEligible(Workspace $workspace, User $user, ConnectedAccount $account): bool
    {
        return $account->platform->supportsNativeRead() && $this->watchWithinCap($workspace, $user, $account);
    }

    public function untrack(ConnectedAccount $account): void
    {
        ConnectedAccountNativeWatch::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('connected_account_id', $account->id)
            ->delete();
    }

    /**
     * Checks the cap and creates the watch under a lock on the workspace row, so
     * concurrent enables cannot both pass the cap. Returns whether the account is tracked.
     */
    private function watchWithinCap(Workspace $workspace, User $user, ConnectedAccount $account): bool
    {
        return DB::transaction(function () use ($workspace, $user, $account): bool {
            $locked = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            if (! $account->nativeWatch()->exists() && ! $this->gate->canTrackNativeAccount($locked)) {
                return false;
            }

            ConnectedAccountNativeWatch::query()->firstOrCreate(
                ['connected_account_id' => $account->id],
                ['workspace_id' => $workspace->id, 'enabled_at' => now(), 'enabled_by' => $user->id],
            );

            return true;
        });
    }
}
