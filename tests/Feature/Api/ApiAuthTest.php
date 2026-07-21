<?php

use App\Models\ApiKey;
use App\Services\Api\ApiKeyManager;

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
