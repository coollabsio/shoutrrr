<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Api\ApiKeyManager;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

beforeEach(function () {
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys', ['--no-interaction' => true]);
    }

    Client::factory()->asPersonalAccessTokenClient()->create(['provider' => 'users']);

    $this->manager = app(ApiKeyManager::class);
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create();
});

test('issue returns a plaintext token and persists the key row', function () {
    [$apiKey, $plain] = $this->manager->issue($this->workspace, $this->user, 'CI bot', 'write', null);

    expect($plain)->toBeString()->not->toBeEmpty();
    expect($apiKey)->toBeInstanceOf(ApiKey::class);
    expect($apiKey->workspace_id)->toBe($this->workspace->id);
    expect($apiKey->user_id)->toBe($this->user->id);
    expect($apiKey->scope)->toBe('write');
    expect($apiKey->expires_at)->toBeNull();
    expect(Token::find($apiKey->access_token_id))->not->toBeNull();
});

test('a read key is granted only the read scope', function () {
    [$apiKey] = $this->manager->issue($this->workspace, $this->user, 'reader', 'read', null);

    expect(Token::find($apiKey->access_token_id)->scopes)->toBe(['read']);
});

test('a write key is granted read and write scopes', function () {
    [$apiKey] = $this->manager->issue($this->workspace, $this->user, 'writer', 'write', null);

    expect(Token::find($apiKey->access_token_id)->scopes)->toBe(['read', 'write']);
});

test('a custom expiry is stored on the key', function () {
    $expires = now()->addDays(7)->startOfSecond();

    [$apiKey] = $this->manager->issue($this->workspace, $this->user, 'temp', 'read', $expires);

    expect($apiKey->expires_at->equalTo($expires))->toBeTrue();
});

test('last_four matches the last 4 characters of the plaintext token', function () {
    [$apiKey, $plain] = $this->manager->issue($this->workspace, $this->user, 'CI bot', 'write', null);

    expect($apiKey->last_four)->toBe(substr($plain, -4));
});

test('a key issued with a custom expiry produces a Passport token expiring at that instant', function () {
    $expires = now()->addDays(7)->startOfSecond();

    [$apiKey] = $this->manager->issue($this->workspace, $this->user, 'temp', 'read', $expires);

    $token = Token::find($apiKey->access_token_id);

    expect($token->expires_at->diffInMinutes($expires, absolute: true))->toBeLessThanOrEqual(1);
    expect($apiKey->expires_at->diffInMinutes($expires, absolute: true))->toBeLessThanOrEqual(1);
});

test('a non-expiring key produces a Passport token expiring far in the future', function () {
    [$apiKey] = $this->manager->issue($this->workspace, $this->user, 'forever', 'write', null);

    $token = Token::find($apiKey->access_token_id);

    expect($token->expires_at->diffInDays(now(), absolute: true))->toBeGreaterThanOrEqual(99 * 365);
    expect($apiKey->expires_at)->toBeNull();
});

test('revoke marks the row revoked and revokes the passport token', function () {
    [$apiKey] = $this->manager->issue($this->workspace, $this->user, 'bot', 'write', null);

    $this->manager->revoke($apiKey);

    expect($apiKey->fresh()->revoked_at)->not->toBeNull();
    expect(Token::find($apiKey->access_token_id)->revoked)->toBeTrue();
});
