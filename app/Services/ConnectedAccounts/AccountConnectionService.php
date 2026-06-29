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
}
