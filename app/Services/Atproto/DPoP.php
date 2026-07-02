<?php

declare(strict_types=1);

namespace App\Services\Atproto;

use App\Models\InstanceSetting;
use App\Support\InstanceSettings;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;
use Throwable;

class DPoP
{
    private const string SETTING_KEY = 'oauth_signing_key';

    /**
     * @return array{kty: string, crv: string, x: string, y: string, d: string}
     */
    public function generateKey(): array
    {
        $jwkSet = json_decode(EC::createKey('secp256r1')->toString('JWK'), true, flags: JSON_THROW_ON_ERROR);
        $jwk = $jwkSet['keys'][0] ?? null;

        if (! is_array($jwk)) {
            throw new RuntimeException('Could not generate a DPoP key.');
        }

        $key = $this->normalizeJwk($jwk);

        if ($key === null) {
            throw new RuntimeException('Could not generate a DPoP key.');
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $jwk
     * @return array{kty: string, crv: string, x: string, y: string, d: string}|null
     */
    private function normalizeJwk(array $jwk): ?array
    {
        if (! isset($jwk['kty'], $jwk['crv'], $jwk['x'], $jwk['y'], $jwk['d'])) {
            return null;
        }

        $kty = (string) $jwk['kty'];
        $crv = (string) $jwk['crv'];

        if ($kty !== 'EC' || $crv !== 'P-256') {
            return null;
        }

        return [
            'kty' => $kty,
            'crv' => $crv,
            'x' => (string) $jwk['x'],
            'y' => (string) $jwk['y'],
            'd' => (string) $jwk['d'],
        ];
    }

    /**
     * @param  array{kty: string, crv: string, x: string, y: string, d: string}  $jwk
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
            'iat' => time() - 30,
            'exp' => time() + 300,
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
     * @return array{kty: string, crv: string, x: string, y: string, d: string}
     */
    public function signingKey(): array
    {
        // Fast path: the key already exists, so avoid taking a distributed lock on
        // every OAuth operation (PAR, token exchange, refresh, JWKS fetch).
        $existing = $this->readSigningKey();

        if ($existing !== null) {
            return $existing;
        }

        // First-time generation is serialized so concurrent requests don't each
        // persist a different key and invalidate one another's published JWKS.
        return Cache::lock('atproto-signing-key', 60)
            ->block(10, function (): array {
                $existing = $this->readSigningKey();

                if ($existing !== null) {
                    return $existing;
                }

                $key = $this->generateKey();

                // Persist through the model so the encrypted blob is JSON-encoded into
                // the `value` json column — a raw write would be rejected by Postgres/
                // MySQL json columns as invalid JSON (it only survives on SQLite).
                InstanceSetting::query()->updateOrCreate(
                    ['key' => self::SETTING_KEY],
                    ['value' => Crypt::encryptString(json_encode($key, JSON_THROW_ON_ERROR))],
                );

                // The shared instance-settings cache eager-loads every row; drop it so
                // the freshly written key isn't masked by a stale snapshot.
                Cache::forget(InstanceSettings::CacheKey);

                return $key;
            });
    }

    /**
     * @return array{kty: string, crv: string, x: string, y: string, d: string}|null
     */
    private function readSigningKey(): ?array
    {
        $setting = InstanceSetting::query()->find(self::SETTING_KEY);

        if ($setting === null) {
            return null;
        }

        try {
            return $this->normalizeJwk(
                json_decode(
                    (string) Crypt::decryptString((string) $setting->value),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
            );
        } catch (Throwable $e) {
            Log::warning('Atproto signing key could not be read; regenerating.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array{kty: string, crv: string, x: string, y: string, d: string}  $signingKey
     */
    public function kid(array $signingKey): string
    {
        $json = json_encode(['crv' => 'P-256', 'kty' => 'EC', 'x' => $signingKey['x'], 'y' => $signingKey['y']], JSON_THROW_ON_ERROR);

        return $this->base64Url(hash('sha256', $json, true));
    }

    /**
     * @param  array{kty: string, crv: string, x: string, y: string, d: string}  $signingKey
     */
    public function clientAssertion(string $issuer, array $signingKey, string $clientId = ''): string
    {
        $now = time();

        return $this->jwt(
            ['alg' => 'ES256', 'kid' => $this->kid($signingKey)],
            [
                'iss' => $clientId,
                'sub' => $clientId,
                'aud' => $issuer,
                'iat' => $now - 30,
                'exp' => $now + 300,
                'jti' => $this->base64Url(random_bytes(16)),
            ],
            $signingKey,
        );
    }

    /**
     * @param  array{kty: string, crv: string, x: string, y: string, d: string}  $signingKey
     * @return array{keys: list<array<string, mixed>>}
     */
    public function publicJwks(array $signingKey): array
    {
        return [
            'keys' => [
                [
                    'kty' => 'EC',
                    'crv' => 'P-256',
                    'x' => $signingKey['x'],
                    'y' => $signingKey['y'],
                    'use' => 'sig',
                    'alg' => 'ES256',
                    'kid' => $this->kid($signingKey),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     * @param  array{kty: string, crv: string, x: string, y: string, d: string}  $jwk
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
