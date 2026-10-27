<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class XAccountTierRefresher
{
    public function __construct(
        private readonly XAccountCapabilities $xCapabilities,
        private readonly TokenManager $tokens,
    ) {}

    /**
     * Re-read the X subscription tier and store it on the account. Returns false
     * when the lookup fails, leaving the existing limits in place.
     */
    public function refresh(ConnectedAccount $account): bool
    {
        try {
            $credentials = $this->tokens->fresh($account);
            $capabilities = $this->xCapabilities->tryForAccessToken(
                isset($credentials['access_token']) ? (string) $credentials['access_token'] : null,
            );
        } catch (Throwable $exception) {
            Log::warning('Could not refresh X account subscription tier.', [
                'account_id' => $account->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($capabilities === null) {
            return false;
        }

        // Lock the row for the capabilities read-modify-write so a concurrent
        // auto-repost toggle (which writes the same JSON column) can't clobber
        // the freshly-fetched tier, or vice versa.
        DB::transaction(function () use ($account, $capabilities): void {
            $locked = ConnectedAccount::query()->lockForUpdate()->findOrFail($account->id);

            $locked->forceFill([
                'capabilities' => array_replace($locked->capabilities ?? [], $capabilities),
                // A successful authenticated X lookup proves the stored access token
                // is usable. Recover an account that a previous refresh failure left
                // blocked, otherwise publishing would fail before it can use that
                // still-valid token.
                'status' => ConnectedAccountStatus::Active->value,
                'refresh_failed_at' => null,
                'refresh_failure_reason' => null,
            ])->save();
        });

        // The write landed on the locked instance; refresh so callers read the
        // newly-stored tier rather than the pre-refresh capabilities.
        $account->refresh();

        return true;
    }
}
