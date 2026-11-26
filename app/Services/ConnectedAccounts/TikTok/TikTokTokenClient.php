<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use App\Enums\UsageCategory;
use App\Exceptions\TokenRefreshException;
use App\Exceptions\TransientTokenRefreshException;
use App\Models\ConnectedAccount;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * TikTok's token refresh and revoke endpoints. Every token response reports
 * how long the access token (`expires_in`) and the refresh token
 * (`refresh_expires_in`) have left, and TikTok asks clients to schedule from
 * those rather than assume fixed lifetimes. A refresh can hand back a new
 * refresh token and invalidate the previous one at once, so callers must
 * persist the returned pair before doing anything else.
 *
 * TikTok reports OAuth failures in the body (`{error, error_description,
 * log_id}`), so a response is classified by that, not by HTTP status alone.
 *
 * @see https://developers.tiktok.com/doc/oauth-user-access-token-management
 * @see https://developers.tiktok.com/doc/oauth-error-handling
 */
class TikTokTokenClient
{
    use TracksUsage;

    public const string TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    private const string REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    /**
     * OAuth error codes TikTok documents as temporary on its side.
     */
    private const array TRANSIENT_ERRORS = ['server_error', 'temporarily_unavailable'];

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * `refresh_expires_at` is null when TikTok leaves out the refresh token's lifetime.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: CarbonImmutable, refresh_expires_at: CarbonImmutable|null}
     *
     * @throws TokenRefreshException when TikTok rejects the refresh token (reconnect needed)
     * @throws TransientTokenRefreshException when TikTok or the network is temporarily unavailable
     */
    public function refresh(ConnectedAccount $account, string $refreshToken): array
    {
        try {
            $response = $this->http->asForm()->acceptJson()->timeout(10)->connectTimeout(5)
                ->post(self::TOKEN_URL, [
                    'client_key' => (string) config('services.tiktok.client_id'),
                    'client_secret' => (string) config('services.tiktok.client_secret'),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
        } catch (ConnectionException $exception) {
            throw new TransientTokenRefreshException('TikTok token refresh failed: could not reach TikTok.', previous: $exception);
        }

        $accessToken = $response->json('access_token');
        $rotatedRefreshToken = $response->json('refresh_token');
        $succeeded = $this->errorCode($response) === null
            && is_string($accessToken) && $accessToken !== ''
            && is_string($rotatedRefreshToken) && $rotatedRefreshToken !== '';

        $this->meter(UsageCategory::ExternalApi, UsageOperation::TOKEN_REFRESH, $account, $response, succeeded: $succeeded);

        if ($succeeded) {
            $refreshExpiresIn = (int) $response->json('refresh_expires_in');

            return [
                'access_token' => $accessToken,
                'refresh_token' => $rotatedRefreshToken,
                'expires_at' => Date::now()->addSeconds((int) $response->json('expires_in'))->toImmutable(),
                'refresh_expires_at' => $refreshExpiresIn > 0 ? Date::now()->addSeconds($refreshExpiresIn)->toImmutable() : null,
            ];
        }

        $message = 'TikTok token refresh failed: '.$this->describe($response);

        if ($response->status() === 429 || $response->serverError() || in_array($this->errorCode($response), self::TRANSIENT_ERRORS, true)) {
            throw new TransientTokenRefreshException($message);
        }

        throw new TokenRefreshException($message);
    }

    /**
     * Revoke the user's grant so the app disappears from their TikTok
     * "connected apps" — TikTok asks clients to do this when a user disconnects.
     *
     * @throws RuntimeException|ConnectionException when TikTok does not confirm the revocation
     */
    public function revoke(string $accessToken): void
    {
        $response = $this->http->asForm()->acceptJson()->timeout(10)->connectTimeout(5)
            ->post(self::REVOKE_URL, [
                'client_key' => (string) config('services.tiktok.client_id'),
                'client_secret' => (string) config('services.tiktok.client_secret'),
                'token' => $accessToken,
            ]);

        if ($response->failed() || $this->errorCode($response) !== null) {
            throw new RuntimeException('TikTok token revoke failed: '.$this->describe($response));
        }
    }

    private function errorCode(Response $response): ?string
    {
        $error = $response->json('error');

        if (is_array($error)) {
            $error = $error['code'] ?? null;
        }

        return is_string($error) && $error !== '' && $error !== 'ok' ? $error : null;
    }

    private function describe(Response $response): string
    {
        $description = $response->json('error_description');

        return ($this->errorCode($response) ?? "HTTP {$response->status()}")
            .(is_string($description) && $description !== '' ? " ({$description})" : '');
    }
}
