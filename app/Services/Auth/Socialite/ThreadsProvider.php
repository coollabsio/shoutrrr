<?php

declare(strict_types=1);

namespace App\Services\Auth\Socialite;

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

/**
 * Hand-rolled Socialite driver for Threads.
 *
 * Threads is a separate OAuth surface from the rest of the Meta platforms:
 * it authorizes at threads.net but exchanges tokens and reads profile data
 * against graph.threads.net, and its short-lived token response omits
 * `expires_in` entirely (the token is valid ~1h; long-lived exchange and
 * refresh happen out-of-band of Socialite). Laravel Socialite has no
 * first-party driver for it, so this mirrors the app's existing bespoke
 * approach to non-standard OAuth surfaces and is registered via
 * `Socialite::extend()` in `AppServiceProvider::boot()`.
 */
class ThreadsProvider extends AbstractProvider
{
    protected $scopeSeparator = ',';

    /**
     * Mirrors Platform::Threads->scopes(); the connect flow
     * (OAuthConnectionController) always calls setScopes() explicitly, but
     * this keeps the driver usable/testable on its own.
     *
     * @var array<int, string>
     */
    protected $scopes = ['threads_basic', 'threads_content_publish', 'threads_manage_replies', 'threads_manage_insights'];

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase('https://threads.net/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://graph.threads.net/oauth/access_token';
    }

    /**
     * @param  string  $code
     * @return array<string, string>
     */
    protected function getTokenFields($code): array
    {
        return array_merge(parent::getTokenFields($code), [
            // Threads requires this explicitly on the token request even
            // though it's already the AbstractProvider default.
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * Threads' token response is `{access_token, user_id}` — no
     * `expires_in`. This goes through Laravel's HTTP client (rather than the
     * raw Guzzle client `getHttpClient()` returns) so it can be exercised
     * with `Http::fake()` in tests, consistent with the rest of the app's
     * outbound HTTP calls.
     *
     * @param  string  $code
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code): array
    {
        $response = Http::asForm()->post($this->getTokenUrl(), $this->getTokenFields($code));

        $response->throw();

        return (array) $response->json();
    }

    /**
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = Http::get('https://graph.threads.net/v1.0/me', [
            'fields' => 'id,username,threads_profile_picture_url',
            'access_token' => $token,
        ]);

        $response->throw();

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'] ?? null,
            'nickname' => $user['username'] ?? null,
            'name' => $user['username'] ?? null,
            'avatar' => $user['threads_profile_picture_url'] ?? null,
        ]);
    }
}
