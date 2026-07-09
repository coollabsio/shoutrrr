<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\Threads;

use App\Exceptions\TokenRefreshException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;

class ThreadsTokenExchanger
{
    private const string BASE_URL = 'https://graph.threads.net';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Exchange the short-lived (~1h) token Socialite hands back from the code
     * exchange for a long-lived (~60d) one. Must happen immediately after the
     * OAuth callback, before the account is persisted.
     *
     * @return array{token: string, expiresAt: CarbonImmutable}
     */
    public function exchangeForLongLived(string $shortToken): array
    {
        $response = $this->http->get(self::BASE_URL.'/access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => config('services.threads.client_secret'),
            'access_token' => $shortToken,
        ]);

        if ($response->failed()) {
            throw new TokenRefreshException('Threads long-lived token exchange failed: '.$this->errorDetail($response));
        }

        return $this->tokenPayload($response);
    }

    /**
     * Refresh a long-lived Threads token before it lapses. The token must be
     * at least 24h old and not yet expired; Threads has no separate refresh
     * token — the long-lived access token refreshes itself.
     *
     * @return array{token: string, expiresAt: CarbonImmutable}
     */
    public function refresh(string $longToken): array
    {
        $response = $this->http->get(self::BASE_URL.'/refresh_access_token', [
            'grant_type' => 'th_refresh_token',
            'access_token' => $longToken,
        ]);

        if ($response->failed()) {
            throw new TokenRefreshException('Threads token refresh failed: '.$this->errorDetail($response));
        }

        return $this->tokenPayload($response);
    }

    /**
     * Surface the Graph API's own error text (`error_description` / `error`)
     * in the exception so token failures are debuggable in production.
     */
    private function errorDetail(Response $response): string
    {
        // Meta returns `error` as either a string or a nested object
        // (`{"error": {"message": ...}}`); normalize both to a string.
        $error = $response->json('error');

        if (is_array($error)) {
            $error = $error['message'] ?? null;
        }

        return (string) ($response->json('error_description') ?? $error ?? $response->body());
    }

    /**
     * @return array{token: string, expiresAt: CarbonImmutable}
     */
    private function tokenPayload(Response $response): array
    {
        return [
            'token' => (string) $response->json('access_token'),
            'expiresAt' => Date::now()->addSeconds((int) $response->json('expires_in'))->toImmutable(),
        ];
    }
}
