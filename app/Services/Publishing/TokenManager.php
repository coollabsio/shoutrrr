<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Services\Atproto\DPoP;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

class TokenManager
{
    use TracksUsage;

    private const int SKEW_SECONDS = 120;

    private const string BLUESKY_DEFAULT_PDS = 'https://bsky.social';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly DPoP $dpop,
    ) {}

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

        if ($account->platform === Platform::Bluesky && $account->auth_method === 'app_password') {
            return $this->blueskyCredentials($account, $secret);
        }

        if ($account->platform === Platform::Bluesky && $account->auth_method === 'oauth') {
            return $this->blueskyOAuthCredentials($account, $secret, $force);
        }

        if (! $force && ! $this->needsRefresh($account)) {
            return ['access_token' => $secret->access_token];
        }

        return Cache::lock("connected-account-token-refresh:{$account->id}", 60)
            ->block(10, function () use ($account, $force): array {
                $freshAccount = $account->newQueryWithoutScopes()->findOrFail($account->id);
                $freshSecret = $freshAccount->secret()->firstOrFail();

                if (! $force && ! $this->needsRefresh($freshAccount)) {
                    return ['access_token' => $freshSecret->access_token];
                }

                return $this->refreshOAuth($freshAccount, $freshSecret);
            });
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
    private function blueskyOAuthCredentials(ConnectedAccount $account, ConnectedAccountSecret $secret, bool $force): array
    {
        if (! $force && ! $this->needsRefresh($account)) {
            return $this->blueskyOAuthPayload($secret);
        }

        // Bluesky (ATProto) OAuth refresh tokens are single-use and rotate on every
        // refresh. Concurrent refreshers — the hourly force-sweep plus the publish,
        // reply-fetch, and engagement jobs that all call fresh() — would otherwise
        // race: the winner rotates the token, the loser POSTs the already-consumed
        // one and 400s with invalid_grant, flipping the account to needs-attention.
        // Serialize per account and re-read the rotated state under the lock, exactly
        // as the generic OAuth path below does.
        return Cache::lock("connected-account-token-refresh:{$account->id}", 60)
            ->block(10, function () use ($account, $force): array {
                $freshAccount = $account->newQueryWithoutScopes()->findOrFail($account->id);
                $freshSecret = $freshAccount->secret()->firstOrFail();

                if (! $force && ! $this->needsRefresh($freshAccount)) {
                    return $this->blueskyOAuthPayload($freshSecret);
                }

                return $this->refreshOAuth($freshAccount, $freshSecret);
            });
    }

    /**
     * Hand the publisher a fresh Bluesky session. The accessJwt minted at connect
     * time expires ~2h later, so a draft scheduled or published any later fails
     * with "ExpiredToken". Mint a new accessJwt first: refresh with the long-lived
     * refreshJwt, then fall back to a full app-password login if that token has
     * also lapsed. Only when both fail do we surface the account as needing
     * attention and return the stale session (the publish then fails cleanly).
     *
     * @return array<string, mixed>
     */
    private function blueskyCredentials(ConnectedAccount $account, ConnectedAccountSecret $secret): array
    {
        $session = $secret->session ?? [];
        $pds = (string) ($session['pds'] ?? self::BLUESKY_DEFAULT_PDS);

        $tokens = $this->refreshBlueskySession($pds, (string) ($session['refreshJwt'] ?? ''), $account)
            ?? $this->createBlueskySession($pds, (string) $account->remote_account_id, (string) $secret->app_password, $account);

        if ($tokens === null) {
            $account->forceFill([
                'status' => ConnectedAccountStatus::NeedsAttention->value,
                'refresh_failed_at' => Date::now(),
                'refresh_failure_reason' => 'Bluesky session refresh and app-password login failed.',
            ])->save();

            return ['session' => $session, 'app_password' => $secret->app_password];
        }

        $session = [...$session, ...$tokens, 'pds' => $pds];
        $secret->forceFill(['session' => $session])->save();
        $account->forceFill([
            'last_refreshed_at' => Date::now(),
            'status' => ConnectedAccountStatus::Active->value,
            'refresh_failed_at' => null,
            'refresh_failure_reason' => null,
        ])->save();

        return ['session' => $session, 'app_password' => $secret->app_password];
    }

    /**
     * Exchange the refreshJwt for a new access/refresh pair. Returns null when the
     * refresh token is absent or rejected, so the caller can fall back to a login.
     *
     * @return array{accessJwt: string, refreshJwt: string}|null
     */
    private function refreshBlueskySession(string $pds, string $refreshJwt, ConnectedAccount $account): ?array
    {
        if ($refreshJwt === '') {
            return null;
        }

        // refreshSession authenticates with the refreshJwt as the bearer token.
        $response = $this->http->withToken($refreshJwt)->acceptJson()
            ->post($pds.'/xrpc/com.atproto.server.refreshSession');

        $this->meter(UsageCategory::ExternalApi, UsageOperation::TOKEN_REFRESH, $account, $response);

        return $this->blueskyTokens($response);
    }

    /**
     * Mint a brand-new session from the stored app password (which does not
     * expire). The DID is used as the login identifier.
     *
     * @return array{accessJwt: string, refreshJwt: string}|null
     */
    private function createBlueskySession(string $pds, string $identifier, string $appPassword, ConnectedAccount $account): ?array
    {
        if ($identifier === '' || $appPassword === '') {
            return null;
        }

        $response = $this->http->acceptJson()
            ->post($pds.'/xrpc/com.atproto.server.createSession', [
                'identifier' => $identifier,
                'password' => $appPassword,
            ]);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::TOKEN_REFRESH, $account, $response);

        return $this->blueskyTokens($response);
    }

    /**
     * @return array{accessJwt: string, refreshJwt: string}|null
     */
    private function blueskyTokens(Response $response): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $accessJwt = (string) $response->json('accessJwt');
        $refreshJwt = (string) $response->json('refreshJwt');

        if ($accessJwt === '' || $refreshJwt === '') {
            return null;
        }

        return ['accessJwt' => $accessJwt, 'refreshJwt' => $refreshJwt];
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshOAuth(ConnectedAccount $account, ConnectedAccountSecret $secret): array
    {
        if ($account->platform === Platform::Bluesky) {
            $endpoint = (string) ($secret->session['token_endpoint'] ?? '');
            $issuer = (string) ($secret->session['issuer'] ?? $secret->session['auth_server'] ?? $endpoint);
        } elseif ($account->platform === Platform::X) {
            $endpoint = 'https://api.twitter.com/2/oauth2/token';
        } else {
            $endpoint = 'https://www.linkedin.com/oauth/v2/accessToken';
        }

        $configKey = $account->platform->configKey();
        $clientId = $account->platform === Platform::Bluesky
            ? (string) ($secret->session['client_id'] ?? route('oauth.bluesky.metadata'))
            : (string) config($configKey.'.client_id');
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
        } elseif ($account->platform === Platform::Bluesky) {
            /** @var array{kty: string, crv: string, x: string, y: string, d: string}|null $key */
            $key = $secret->session['dpop_private_jwk'] ?? null;
            if ($key === null || $endpoint === '') {
                throw new TokenRefreshException("Token refresh failed for account {$account->id}.");
            }
            // Confidential clients authenticate with a private_key_jwt assertion; the
            // loopback dev client (the synthesized `http://localhost/?…` id) is public
            // and must not send one.
            $usesAssertion = $clientId === route('oauth.bluesky.metadata');
            if ($usesAssertion) {
                $body['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
                $body['client_assertion'] = $this->dpop->clientAssertion($issuer, $this->dpop->signingKey(), $clientId);
            }
            $request = $request->withHeader('DPoP', $this->dpop->proof('POST', $endpoint, $key, nonce: $secret->session['dpop_nonce'] ?? null));
        } else {
            $body['client_secret'] = $clientSecret;
        }

        $response = $request->post((string) $endpoint, $body);

        if ($response->failed() && $account->platform === Platform::Bluesky) {
            $nonce = $response->header('DPoP-Nonce');
            if ($nonce !== '') {
                if ($usesAssertion) {
                    $body['client_assertion'] = $this->dpop->clientAssertion($issuer, $this->dpop->signingKey(), $clientId);
                }
                $response = $this->http->asForm()
                    ->withHeader('DPoP', $this->dpop->proof('POST', $endpoint, $key, nonce: $nonce))
                    ->post((string) $endpoint, $body);
            }
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::TOKEN_REFRESH, $account, $response);

        if ($response->failed()) {
            $account->forceFill([
                'status' => ConnectedAccountStatus::NeedsAttention->value,
                'refresh_failed_at' => Date::now(),
                'refresh_failure_reason' => $this->refreshFailureReason($response),
            ])->save();

            throw new TokenRefreshException("Token refresh failed for account {$account->id}.");
        }

        $accessToken = (string) $response->json('access_token');
        $refreshToken = $response->json('refresh_token') ?? $secret->refresh_token;
        $expiresIn = (int) ($response->json('expires_in') ?? 0);

        $secret->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'session' => $account->platform === Platform::Bluesky
                ? [...($secret->session ?? []), 'dpop_nonce' => $response->header('DPoP-Nonce')]
                : $secret->session,
        ])->save();

        $account->forceFill([
            'token_expires_at' => $expiresIn > 0 ? Date::now()->addSeconds($expiresIn) : null,
            'last_refreshed_at' => Date::now(),
            'status' => ConnectedAccountStatus::Active->value,
            'refresh_failed_at' => null,
            'refresh_failure_reason' => null,
        ])->save();

        return $account->platform === Platform::Bluesky
            ? $this->blueskyOAuthPayload($secret->refresh())
            : ['access_token' => $accessToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function blueskyOAuthPayload(ConnectedAccountSecret $secret): array
    {
        $session = $secret->session ?? [];

        return [
            'access_token' => $secret->access_token,
            'session' => [
                ...$session,
                'accessJwt' => $secret->access_token,
            ],
        ];
    }

    private function refreshFailureReason(Response $response): string
    {
        $message = (string) ($response->json('error_description')
            ?? $response->json('error')
            ?? $response->json('message')
            ?? 'OAuth token refresh failed.');

        return "HTTP {$response->status()}: {$message}";
    }
}
