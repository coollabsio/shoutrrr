<?php

use App\Models\InstanceSetting;
use App\Services\Atproto\DPoP;
use Illuminate\Support\Facades\Crypt;

function base64UrlJson(string $part): array
{
    return json_decode(base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true), true, flags: JSON_THROW_ON_ERROR);
}

test('it creates signed dpop proofs with public jwk metadata', function () {
    $dpop = app(DPoP::class);
    $key = $dpop->generateKey();
    $proof = $dpop->proof('post', 'https://bsky.social/xrpc/com.atproto.repo.createRecord?x=1', $key, 'access-token', 'nonce-1');
    [$header, $payload, $signature] = explode('.', $proof);

    expect(base64UrlJson($header))
        ->toMatchArray(['typ' => 'dpop+jwt', 'alg' => 'ES256'])
        ->and(base64UrlJson($header)['jwk'])->toHaveKeys(['kty', 'crv', 'x', 'y'])
        ->and(base64UrlJson($payload))->toMatchArray([
            'htm' => 'POST',
            'htu' => 'https://bsky.social/xrpc/com.atproto.repo.createRecord',
            'nonce' => 'nonce-1',
        ])
        ->and($signature)->not->toBeEmpty();
});

test('it persists a stable signing key and stores the value as valid json', function () {
    $dpop = app(DPoP::class);

    $first = $dpop->signingKey();
    $second = $dpop->signingKey();

    // Same key across calls (persisted, not regenerated each time).
    expect($first)->toBe($second)
        ->and($first)->toHaveKeys(['kty', 'crv', 'x', 'y', 'd']);

    // Stored through the json-cast column, so the raw DB value must be valid JSON
    // (a raw encrypted blob would be rejected by Postgres/MySQL json columns).
    $raw = InstanceSetting::query()->find('oauth_signing_key')->getRawOriginal('value');
    expect(json_decode($raw, flags: JSON_THROW_ON_ERROR))->toBeString();
});

test('it exposes the public jwks without the private component', function () {
    $dpop = app(DPoP::class);
    $key = $dpop->signingKey();

    $jwks = $dpop->publicJwks($key);

    expect($jwks['keys'][0])
        ->toMatchArray(['kty' => 'EC', 'crv' => 'P-256', 'use' => 'sig', 'alg' => 'ES256'])
        ->toHaveKey('kid', $dpop->kid($key))
        ->and($jwks['keys'][0])->not->toHaveKey('d');
});

test('it regenerates the signing key when the stored value is unreadable', function () {
    // A value that decrypts-fails (not valid ciphertext) — simulates key rotation
    // or corruption. Written via the model so it lands in the json column cleanly.
    InstanceSetting::query()->create(['key' => 'oauth_signing_key', 'value' => 'not-real-ciphertext']);

    $key = app(DPoP::class)->signingKey();

    expect($key)->toHaveKeys(['kty', 'crv', 'x', 'y', 'd']);

    // The corrupt value was replaced with a readable, decryptable one.
    $stored = InstanceSetting::query()->find('oauth_signing_key')->value;
    expect(json_decode(Crypt::decryptString($stored), true, flags: JSON_THROW_ON_ERROR))->toBe($key);
});
