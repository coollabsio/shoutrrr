<?php

declare(strict_types=1);

namespace App\Services\Atproto;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;

class DPoP
{
    /**
     * @return array{kty: string, crv: string, x: string, y: string, d: string}
     */
    public function generateKey(): array
    {
        $jwkSet = json_decode(EC::createKey('secp256r1')->toString('JWK'), true, flags: JSON_THROW_ON_ERROR);
        $jwk = $jwkSet['keys'][0] ?? null;

        if (! is_array($jwk) || ! isset($jwk['x'], $jwk['y'], $jwk['d'])) {
            throw new RuntimeException('Could not generate a DPoP key.');
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => (string) $jwk['x'],
            'y' => (string) $jwk['y'],
            'd' => (string) $jwk['d'],
        ];
    }

    /**
     * @param  array<string, string>  $jwk
     */
    public function proof(string $method, string $url, array $jwk, ?string $accessToken = null, ?string $nonce = null): string
    {
        $publicJwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $jwk['x'],
            'y' => $jwk['y'],
        ];

        $payload = [
            'jti' => (string) Str::uuid(),
            'htm' => strtoupper($method),
            'htu' => strtok($url, '?') ?: $url,
            'iat' => time(),
        ];

        if ($accessToken !== null && $accessToken !== '') {
            $payload['ath'] = $this->base64Url(hash('sha256', $accessToken, true));
        }

        if ($nonce !== null && $nonce !== '') {
            $payload['nonce'] = $nonce;
        }

        return $this->jwt(['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $publicJwk], $payload, $jwk);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $jwk
     */
    private function jwt(array $header, array $payload, array $jwk): string
    {
        $privateKey = PublicKeyLoader::loadPrivateKey(json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR));

        if (! $privateKey instanceof PrivateKey) {
            throw new RuntimeException('Could not load the DPoP key.');
        }

        return JWT::encode(
            $payload,
            $privateKey->toString('PKCS8'),
            'ES256',
            null,
            $header,
        );
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
