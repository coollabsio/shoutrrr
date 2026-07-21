<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use RuntimeException;

class ApiKeyManager
{
    /**
     * Issue an API key: mint a Passport personal access token and record the
     * workspace binding + metadata. Returns [ApiKey, plaintextToken]; the
     * plaintext is shown to the user exactly once.
     *
     * @param  'read'|'write'  $scope
     * @return array{0: ApiKey, 1: string}
     */
    public function issue(Workspace $workspace, User $user, string $name, string $scope, ?CarbonInterface $expiresAt): array
    {
        if (! in_array($scope, ['read', 'write'], true)) {
            throw new InvalidArgumentException("Invalid API key scope [{$scope}]; expected 'read' or 'write'.");
        }

        $scopes = $scope === 'write' ? ['read', 'write'] : ['read'];

        $this->ensureEncryptionKeysExist();
        $this->ensurePersonalAccessClientExists();

        // The JWT `exp` claim is baked in at mint time from this global, so it
        // must be set to the real per-key lifetime right before createToken().
        // Setting only api_keys.expires_at would leave the JWT silently
        // expiring at whatever the last-set global was. This is safe to mutate
        // here (even under Octane) because ApiKeyManager is the ONLY personal
        // access token issuer in this app, and every issue() call sets this
        // global explicitly before minting — each call is self-correcting.
        Passport::personalAccessTokensExpireIn($expiresAt ?? now()->addYears(100));

        $result = $user->createToken($name, $scopes);

        $apiKey = ApiKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'access_token_id' => $result->accessTokenId,
            'name' => $name,
            'last_four' => substr($result->accessToken, -4),
            'scope' => $scope,
            'expires_at' => $expiresAt,
        ]);

        return [$apiKey, $result->accessToken];
    }

    public function revoke(ApiKey $apiKey): void
    {
        $apiKey->forceFill(['revoked_at' => now()])->save();

        Token::find($apiKey->access_token_id)?->revoke();
    }

    /**
     * Passport signs and verifies every token with an RSA keypair. By default
     * those keys live in storage (oauth-private.key / oauth-public.key) and are
     * gitignored, so a fresh deployment has none until `passport:keys` is run.
     * Rather than make that a mandatory bootstrap step, we generate the pair
     * lazily the first time a key is issued — the same self-provisioning
     * approach as the personal access client below. Skipped when the keys are
     * supplied out of band via env (PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY).
     * Idempotent, and lock-guarded so concurrent first-time issues don't race.
     * Disable via PASSPORT_AUTO_GENERATE_KEYS=false to require the keys be
     * provisioned out of band; issuing then fails loudly if none are present.
     */
    private function ensureEncryptionKeysExist(): void
    {
        if (config('passport.private_key') && config('passport.public_key')) {
            return;
        }

        if (file_exists(Passport::keyPath('oauth-private.key')) && file_exists(Passport::keyPath('oauth-public.key'))) {
            return;
        }

        if (! config('passport.auto_generate_keys')) {
            throw new RuntimeException(
                'Passport encryption keys are missing and PASSPORT_AUTO_GENERATE_KEYS is disabled. '
                .'Run `php artisan passport:keys`, set PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY, '
                .'or set PASSPORT_AUTO_GENERATE_KEYS=true.'
            );
        }

        Cache::lock('shoutrrr:generate-passport-keys', 10)->block(5, function (): void {
            if (file_exists(Passport::keyPath('oauth-private.key')) && file_exists(Passport::keyPath('oauth-public.key'))) {
                return;
            }

            Artisan::call('passport:keys', ['--no-interaction' => true]);
        });
    }

    /**
     * Passport can only mint personal access tokens when a "personal access"
     * grant client exists. This app is the sole issuer of such tokens, so we
     * provision that client lazily on first use rather than seeding it out of
     * band — keeping the requirement next to its only consumer. Idempotent, and
     * lock-guarded so concurrent first-time issues don't create duplicates.
     */
    private function ensurePersonalAccessClientExists(): void
    {
        if ($this->personalAccessClientExists()) {
            return;
        }

        Cache::lock('shoutrrr:create-personal-access-client', 10)->block(5, function (): void {
            if ($this->personalAccessClientExists()) {
                return;
            }

            app(ClientRepository::class)->createPersonalAccessGrantClient(
                config('app.name').' Personal Access Client'
            );
        });
    }

    private function personalAccessClientExists(): bool
    {
        return Client::query()
            ->where('revoked', false)
            ->get()
            ->contains(fn (Client $client): bool => $client->hasGrantType('personal_access'));
    }
}
