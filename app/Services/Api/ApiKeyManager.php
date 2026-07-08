<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

class ApiKeyManager
{
    /**
     * Issue an API key: mint a Passport personal access token and record the
     * workspace binding + metadata. Returns [ApiKey, plaintextToken]; the
     * plaintext is shown to the user exactly once.
     *
     * @return array{0: ApiKey, 1: string}
     */
    public function issue(Workspace $workspace, User $user, string $name, string $scope, ?CarbonInterface $expiresAt): array
    {
        $scopes = $scope === 'write' ? ['read', 'write'] : ['read'];

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
}
