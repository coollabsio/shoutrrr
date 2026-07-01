<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

class BlueskyConnector
{
    private const string DEFAULT_PDS = 'https://bsky.social';

    private const string APPVIEW = 'https://public.api.bsky.app';

    public function __construct(private readonly HttpFactory $http) {}

    public function connect(string $identifier, string $appPassword, ?string $pdsUrl = null): ConnectedAccountData
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $pds = $this->resolvePds($identifier, $pdsUrl);

        // Guard the resolved endpoint too (not just a user override) before sending
        // credentials to it — the DID-document endpoint is attacker-influenceable.
        $this->assertSafePds($pds);

        $sessionResponse = $this->http
            ->timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->post($pds.'/xrpc/com.atproto.server.createSession', [
                'identifier' => $identifier,
                'password' => $appPassword,
            ]);

        if ($sessionResponse->failed()) {
            throw new RuntimeException('Bluesky rejected those credentials. Check the identifier and app password.');
        }

        $session = $sessionResponse->json();
        $did = (string) ($session['did'] ?? '');

        if ($did === '') {
            throw new RuntimeException('Bluesky did not return an account identity.');
        }

        $profileResponse = $this->http
            ->timeout(10)
            ->connectTimeout(5)
            ->withToken((string) $session['accessJwt'])
            ->acceptJson()
            ->get($pds.'/xrpc/app.bsky.actor.getProfile', ['actor' => $did]);

        /** @var array<string, mixed> $profile */
        $profile = $profileResponse->successful() ? (array) $profileResponse->json() : [];

        $handle = $profile['handle'] ?? $session['handle'] ?? $did;

        return new ConnectedAccountData(
            platform: Platform::Bluesky,
            remoteAccountId: $did,
            handle: '@'.$handle,
            displayName: $profile['displayName'] ?? null,
            avatarUrl: $profile['avatar'] ?? null,
            authMethod: 'app_password',
            appPassword: $appPassword,
            session: [
                'accessJwt' => $session['accessJwt'] ?? null,
                'refreshJwt' => $session['refreshJwt'] ?? null,
                'pds' => $pds,
            ],
        );
    }

    public function resolveDid(string $identifier): ?string
    {
        $identifier = $this->normalizeIdentifier($identifier);

        if (str_starts_with($identifier, 'did:')) {
            return $identifier;
        }

        return $this->resolveHandleToDid($identifier);
    }

    public function resolvePds(string $identifier, ?string $override): string
    {
        $did = null;

        return $this->resolvePdsAndDid($identifier, $override, $did);
    }

    public function resolvePdsAndDid(string $identifier, ?string $override, ?string &$did): string
    {
        $identifier = $this->normalizeIdentifier($identifier);

        if ($override !== null && trim($override) !== '') {
            $pds = rtrim(trim($override), '/');
            $this->assertSafePds($pds);

            try {
                $did = $this->resolveDid($identifier);
            } catch (Throwable) {
                // DID resolution is best-effort when a PDS override is provided.
            }

            return $pds;
        }

        try {
            $did = $this->resolveHandleToDid($identifier);
            $pds = $did ? $this->resolveDidToPds($did) : null;

            return $pds ?? self::DEFAULT_PDS;
        } catch (Throwable) {
            return self::DEFAULT_PDS;
        }
    }

    /**
     * Reject PDS endpoints that could turn this server into an SSRF proxy: only
     * https is allowed, and the host must not be localhost or a private/reserved
     * IP literal. (IP-literal + obvious-host checks only — full DNS-rebinding
     * defense is out of scope for M1.)
     *
     * @throws RuntimeException when the endpoint is not a safe public https URL
     */
    private function assertSafePds(string $url): void
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('The Bluesky service URL must use https.');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || $this->isPrivateHost($host)) {
            throw new RuntimeException('That Bluesky service URL is not allowed.');
        }
    }

    private function isPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }

    private function resolveHandleToDid(string $handle): ?string
    {
        $candidates = [self::APPVIEW];

        $parts = explode('.', $handle);
        if (count($parts) > 2) {
            $candidates[] = 'https://'.implode('.', array_slice($parts, 1));
        }
        $candidates[] = 'https://'.$handle;

        foreach ($candidates as $service) {
            try {
                $this->assertSafePds($service);
            } catch (RuntimeException) {
                continue;
            }

            $response = $this->http
                ->timeout(5)
                ->connectTimeout(3)
                ->acceptJson()
                ->get($service.'/xrpc/com.atproto.identity.resolveHandle', ['handle' => $handle]);

            $did = $response->successful() ? $response->json('did') : null;

            if (is_string($did) && str_starts_with($did, 'did:')) {
                return $did;
            }
        }

        return null;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return ltrim(trim($identifier), '@');
    }

    private function resolveDidToPds(string $did): ?string
    {
        $response = $this->http
            ->timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->get('https://plc.directory/'.$did);

        if ($response->failed()) {
            return null;
        }

        /** @var array<int, array{type?: string, serviceEndpoint?: string}> $services */
        $services = $response->json('service', []);

        foreach ($services as $service) {
            if (($service['type'] ?? null) === 'AtprotoPersonalDataServer' && isset($service['serviceEndpoint'])) {
                return rtrim((string) $service['serviceEndpoint'], '/');
            }
        }

        return null;
    }
}
