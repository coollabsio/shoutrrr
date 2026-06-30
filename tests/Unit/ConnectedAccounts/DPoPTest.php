<?php

use App\Services\Atproto\DPoP;

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
