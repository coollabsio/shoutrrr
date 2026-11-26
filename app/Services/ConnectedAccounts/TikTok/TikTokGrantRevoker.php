<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands a TikTok grant back when Shoutrrr stops using it, so the app leaves
 * the creator's TikTok "connected apps", as TikTok asks of clients.
 *
 * Always best effort: a failure is logged, without the token, and never stops
 * the caller. A grant belongs to the TikTok user and this app rather than to
 * one workspace, so it is left alone while any connected account still uses
 * the same TikTok user (open_id): revoking it could cut that account off too.
 *
 * @see https://developers.tiktok.com/doc/oauth-user-access-token-management
 */
class TikTokGrantRevoker
{
    /** How close to expiry a stored access token is treated as already expired. */
    private const int EXPIRY_SKEW_SECONDS = 60;

    public function __construct(private readonly TikTokTokenClient $tokens) {}

    /**
     * Revoke the grant of an account that is being disconnected.
     *
     * This never goes through TokenManager: a failed refresh there flags the
     * account as needing attention and notifies its workspace, which must not
     * happen to an account that is about to be deleted. An expired access
     * token is exchanged once with the stored refresh token instead, and the
     * new pair is not saved.
     */
    public function revokeOnDisconnect(ConnectedAccount $account): void
    {
        if ($this->stillConnected((string) $account->remote_account_id, exceptAccountId: $account->id)) {
            return;
        }

        $this->bestEffort('TikTok grant revocation failed on disconnect.', ['account_id' => $account->id], function () use ($account): void {
            $secret = $account->secret()->first();
            $accessToken = (string) $secret?->access_token;

            if ($accessToken === '' || $account->token_expires_at?->lte(Date::now()->addSeconds(self::EXPIRY_SKEW_SECONDS))) {
                $refreshToken = (string) $secret?->refresh_token;

                if ($refreshToken === '') {
                    return;
                }

                $accessToken = $this->tokens->refresh($account, $refreshToken)['access_token'];
            }

            $this->tokens->revoke($accessToken);
        });
    }

    /**
     * Revoke a grant the connect flow refuses to keep: one missing a permission,
     * or one whose profile TikTok would not return.
     */
    public function revokeRefusedConnection(string $accessToken, ?string $openId): void
    {
        if ($accessToken === '' || ($openId !== null && $openId !== '' && $this->stillConnected($openId))) {
            return;
        }

        $this->bestEffort('TikTok grant revocation failed after a refused connection.', [], fn () => $this->tokens->revoke($accessToken));
    }

    private function stillConnected(string $openId, ?string $exceptAccountId = null): bool
    {
        return ConnectedAccount::query()
            ->withoutGlobalScopes()
            ->where('platform', Platform::TikTok->value)
            ->where('remote_account_id', $openId)
            ->when($exceptAccountId !== null, fn ($query) => $query->whereKeyNot($exceptAccountId))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function bestEffort(string $message, array $context, Closure $revoke): void
    {
        try {
            $revoke();
        } catch (Throwable $exception) {
            Log::warning($message, [
                ...$context,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
