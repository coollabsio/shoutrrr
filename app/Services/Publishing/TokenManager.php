<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;

class TokenManager
{
    private const int SKEW_SECONDS = 120;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Resolve usable credentials for an account, refreshing the OAuth token when it
     * is expired/near-expiry. The proactive sweeper passes `force: true` to refresh
     * every account inside its (wider) window, ahead of the just-in-time skew band.
     *
     * @return array<string, mixed>
     */
    public function fresh(ConnectedAccount $account, bool $force = false): array
    {
        $secret = $account->secret()->firstOrFail();

        if ($account->platform === Platform::Bluesky) {
            return $this->blueskyCredentials($secret);
        }

        if (! $force && ! $this->needsRefresh($account)) {
            return ['access_token' => $secret->access_token];
        }

        return $this->refreshOAuth($account, $secret);
    }

    private function needsRefresh(ConnectedAccount $account): bool
    {
        if ($account->token_expires_at === null) {
            return true;
        }

        return $account->token_expires_at->lte(Date::now()->addSeconds(self::SKEW_SECONDS));
    }

    /**
     * @return array<string, mixed>
     */
    private function blueskyCredentials(ConnectedAccountSecret $secret): array
    {
        return [
            'session' => $secret->session ?? [],
            'app_password' => $secret->app_password,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshOAuth(ConnectedAccount $account, ConnectedAccountSecret $secret): array
    {
        $endpoint = match ($account->platform) {
            Platform::X => 'https://api.twitter.com/2/oauth2/token',
            Platform::LinkedIn => 'https://www.linkedin.com/oauth/v2/accessToken',
            default => null,
        };

        $configKey = $account->platform->configKey();
        $clientId = (string) config($configKey.'.client_id');
        $clientSecret = (string) config($configKey.'.client_secret');

        $request = $this->http->asForm();

        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $secret->refresh_token,
            'client_id' => $clientId,
        ];

        // X is a confidential client (it has a client secret), so its token endpoint
        // requires the credentials via HTTP Basic auth — sending them in the body 401s
        // with "Missing valid authorization header". LinkedIn expects them in the body.
        if ($account->platform === Platform::X) {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        } else {
            $body['client_secret'] = $clientSecret;
        }

        $response = $request->post((string) $endpoint, $body);

        if ($response->failed()) {
            $account->forceFill(['status' => ConnectedAccountStatus::NeedsAttention->value])->save();

            throw new TokenRefreshException("Token refresh failed for account {$account->id}.");
        }

        $accessToken = (string) $response->json('access_token');
        $refreshToken = $response->json('refresh_token') ?? $secret->refresh_token;
        $expiresIn = (int) ($response->json('expires_in') ?? 0);

        $secret->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ])->save();

        $account->forceFill([
            'token_expires_at' => $expiresIn > 0 ? Date::now()->addSeconds($expiresIn) : null,
            'last_refreshed_at' => Date::now(),
            'status' => ConnectedAccountStatus::Active->value,
        ])->save();

        return ['access_token' => $accessToken];
    }
}
