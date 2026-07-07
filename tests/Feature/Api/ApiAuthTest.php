<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Api\ApiKeyManager;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;

/**
 * Issue a real key and return [User, Workspace, plaintextToken]. The user is a
 * member of the workspace.
 *
 * @return array{0: User, 1: Workspace, 2: string}
 */
function issuedKey(string $scope = 'write'): array
{
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys', ['--no-interaction' => true]);
    }

    Client::factory()->asPersonalAccessTokenClient()->create(['provider' => 'users']);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => 'admin']);

    [, $plain] = app(ApiKeyManager::class)->issue($workspace, $user, 'test', $scope, null);

    return [$user, $workspace, $plain];
}

test('a request without a token is 401', function () {
    $this->getJson('/api/v1/connected-accounts')->assertUnauthorized();
});

test('a valid key authenticates', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertOk();
});

test('a revoked key is 401', function () {
    [$user, $workspace, $token] = issuedKey();
    app(ApiKeyManager::class)->revoke(ApiKey::where('user_id', $user->id)->first());

    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertUnauthorized();
});

test('an expired key is 401', function () {
    [$user, , $token] = issuedKey();
    ApiKey::where('user_id', $user->id)->update(['expires_at' => now()->subDay()]);

    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertUnauthorized();
});

test('a key whose user left the workspace is 403', function () {
    [$user, $workspace, $token] = issuedKey();
    $workspace->members()->where('user_id', $user->id)->delete();

    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertForbidden();
});

test('a request touches last_used_at', function () {
    [$user, , $token] = issuedKey();
    expect(ApiKey::where('user_id', $user->id)->first()->last_used_at)->toBeNull();

    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertOk();

    expect(ApiKey::where('user_id', $user->id)->first()->last_used_at)->not->toBeNull();
});
