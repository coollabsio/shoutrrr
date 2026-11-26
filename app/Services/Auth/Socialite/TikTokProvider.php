<?php

declare(strict_types=1);

namespace App\Services\Auth\Socialite;

use App\Services\ConnectedAccounts\TikTok\TikTokGrantRevoker;
use App\Services\ConnectedAccounts\TikTok\TikTokTokenClient;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use Override;
use RuntimeException;
use Throwable;

/**
 * Hand-rolled Socialite driver for TikTok Login Kit (web).
 *
 * TikTok follows the OAuth 2.0 code flow but names the client id `client_key`
 * on both the authorize URL and the token request, separates scopes with
 * commas, and reports token errors as `{error, error_description, log_id}`.
 * Registered via `Socialite::extend()` in `AppServiceProvider::boot()`; state
 * handling (session-stored, single-use) is Socialite's own.
 *
 * @see https://developers.tiktok.com/doc/login-kit-web
 * @see https://developers.tiktok.com/doc/oauth-user-access-token-management
 */
class TikTokProvider extends AbstractProvider
{
    private const string AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const string USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    #[Override]
    protected $scopeSeparator = ',';

    /**
     * Mirrors Platform::TikTok->scopes(); the connect flow always calls
     * setScopes() explicitly, but this keeps the driver usable on its own.
     *
     * @var array<int, string>
     */
    #[Override]
    protected $scopes = ['user.info.basic', 'user.info.profile', 'video.publish', 'video.upload'];

    /** The TikTok user the last exchanged token belongs to. */
    private ?string $issuedOpenId = null;

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(self::AUTHORIZE_URL, $state);
    }

    protected function getTokenUrl(): string
    {
        return TikTokTokenClient::TOKEN_URL;
    }

    /**
     * @param  string|null  $state
     * @return array<string, mixed>
     */
    protected function getCodeFields($state = null): array
    {
        return $this->withClientKey(parent::getCodeFields($state));
    }

    /**
     * @param  string  $code
     * @return array<string, mixed>
     */
    protected function getTokenFields($code): array
    {
        return $this->withClientKey(parent::getTokenFields($code));
    }

    /**
     * Exchange the code through Laravel's HTTP client so it can be faked in
     * tests. TikTok signals failure in the body, so a response is only accepted
     * when it carries an access token and no `error`.
     *
     * @param  string  $code
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code): array
    {
        $response = Http::asForm()->acceptJson()->timeout(10)->connectTimeout(5)
            ->post($this->getTokenUrl(), $this->getTokenFields($code));

        $payload = (array) $response->json();
        $error = $payload['error'] ?? null;

        if ($response->successful() && ($error === null || $error === '') && is_string($payload['access_token'] ?? null)) {
            $this->issuedOpenId = is_string($payload['open_id'] ?? null) ? $payload['open_id'] : null;

            return $payload;
        }

        $code = is_string($error) && $error !== '' ? $error : 'HTTP '.$response->status();
        $description = $payload['error_description'] ?? null;

        throw new RuntimeException(
            'TikTok token exchange failed: '.$code.(is_string($description) && $description !== '' ? " ({$description})" : ''),
        );
    }

    /**
     * Read the creator's profile. The connect flow can't keep a token without
     * one, so when the read fails the grant TikTok just issued is handed back
     * at once instead of being left in the creator's TikTok "connected apps".
     *
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        try {
            return $this->readProfile($token);
        } catch (Throwable $exception) {
            app(TikTokGrantRevoker::class)->revokeRefusedConnection($token, $this->issuedOpenId);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readProfile(string $token): array
    {
        $response = Http::withToken($token)->acceptJson()->timeout(10)->connectTimeout(5)
            ->get(self::USER_INFO_URL, ['fields' => 'open_id,avatar_url,display_name,username']);

        $errorCode = $response->json('error.code');
        $user = $response->json('data.user');

        if (! $response->successful() || ($errorCode !== null && $errorCode !== 'ok') || ! is_array($user)) {
            throw new RuntimeException('TikTok profile lookup failed: '.(is_string($errorCode) ? $errorCode : 'HTTP '.$response->status()));
        }

        if (! is_string($user['open_id'] ?? null) || ! is_string($user['username'] ?? null)) {
            throw new RuntimeException('TikTok profile lookup failed: the user.info.profile scope was not granted.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['open_id'],
            'nickname' => $user['username'],
            'name' => $user['display_name'] ?? null,
            'avatar' => $user['avatar_url'] ?? null,
        ]);
    }

    /**
     * TikTok calls the OAuth client id `client_key`.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function withClientKey(array $fields): array
    {
        $fields['client_key'] = $fields['client_id'];
        unset($fields['client_id']);

        return $fields;
    }
}
