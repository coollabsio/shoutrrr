<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\ConnectedAccountStatus;
use App\Events\ConnectedAccountConnected;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use RuntimeException;

class AccountConnectionService
{
    public function store(ConnectedAccountData $data, User $connectedBy, ?string $workspaceId = null): ConnectedAccount
    {
        $workspaceId ??= Context::get('workspace_id');

        if (! $workspaceId) {
            throw new RuntimeException('Cannot connect an account without an active workspace.');
        }

        $account = DB::transaction(function () use ($data, $connectedBy, $workspaceId): ConnectedAccount {
            $account = ConnectedAccount::withoutGlobalScopes()->updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'platform' => $data->platform->value,
                    'remote_account_id' => $data->remoteAccountId,
                ],
                [
                    'handle' => $data->handle,
                    'display_name' => $data->displayName,
                    'avatar_url' => $data->avatarUrl,
                    'auth_method' => $data->authMethod,
                    'connected_by_user_id' => $connectedBy->id,
                    'status' => ConnectedAccountStatus::Active->value,
                    'capabilities' => $data->capabilities,
                    'token_expires_at' => $data->tokenExpiresAt,
                    'last_refreshed_at' => Date::now(),
                    'refresh_failed_at' => null,
                    'refresh_failure_reason' => null,
                ],
            );

            ConnectedAccountSecret::updateOrCreate(
                ['connected_account_id' => $account->id],
                [
                    'access_token' => $data->accessToken,
                    'refresh_token' => $data->refreshToken,
                    'app_password' => $data->appPassword,
                    'session' => $data->session,
                ],
            );

            return $account;
        });

        ConnectedAccountConnected::dispatch($account);
        Inertia::clearHistory();

        return $account;
    }

    /**
     * Reconnect an existing account in place — adopt fresh credentials and
     * identity onto this row without minting a new one, even when the provider
     * returns a different remote id. A Discord webhook that was deleted and
     * recreated comes back with a new webhook id but should still reconnect the
     * same account card rather than spawn a duplicate.
     */
    public function reconnect(ConnectedAccount $account, ConnectedAccountData $data, User $connectedBy): ConnectedAccount
    {
        DB::transaction(function () use ($account, $data, $connectedBy): void {
            $account->forceFill([
                'remote_account_id' => $data->remoteAccountId,
                'handle' => $data->handle,
                'display_name' => $data->displayName,
                'avatar_url' => $data->avatarUrl,
                'auth_method' => $data->authMethod,
                'connected_by_user_id' => $connectedBy->id,
                'status' => ConnectedAccountStatus::Active->value,
                'capabilities' => $data->capabilities,
                'token_expires_at' => $data->tokenExpiresAt,
                'last_refreshed_at' => Date::now(),
                'refresh_failed_at' => null,
                'refresh_failure_reason' => null,
            ])->save();

            ConnectedAccountSecret::updateOrCreate(
                ['connected_account_id' => $account->id],
                [
                    'access_token' => $data->accessToken,
                    'refresh_token' => $data->refreshToken,
                    'app_password' => $data->appPassword,
                    'session' => $data->session,
                ],
            );
        });

        ConnectedAccountConnected::dispatch($account);
        Inertia::clearHistory();

        return $account;
    }
}
