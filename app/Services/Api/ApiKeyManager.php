<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
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

        $result = $user->createToken($name, $scopes);

        $apiKey = ApiKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'access_token_id' => $result->accessTokenId,
            'name' => $name,
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
