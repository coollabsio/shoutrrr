<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use App\Services\Atproto\DPoP;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class BlueskyOAuthConnector
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly BlueskyConnector $bluesky,
        private readonly DPoP $dpop,
    ) {}

    /**
     * @return array{url: string, state: string, context: array<string, mixed>}
     */
    public function authorizationRedirect(?string $identifier, string $clientId, string $redirectUri, ?string $pdsUrl = null): array
    {
        $identifier = $identifier === null ? null : ltrim(trim($identifier), '@');

        $did = null;
        $pds = match (true) {
            $identifier !== null && $identifier !== '' => $this->bluesky->resolvePdsAndDid($identifier, $pdsUrl, $did),
            $pdsUrl !== null && trim($pdsUrl) !== '' => $this->bluesky->resolvePds('bsky.social', $pdsUrl),
            default => 'https://bsky.social',
        };
        try {
            $metadata = $this->authorizationMetadata($pds);
        } catch (RuntimeException $e) {
            Log::warning('Bluesky OAuth: metadata discovery failed', [
                'pds' => $pds,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        $issuer = (string) ($metadata['issuer'] ?? $pds);

        $state = Str::random(64);
        $verifier = $this->base64Url(random_bytes(64));
        $key = $this->dpop->generateKey();
        $signingKey = $this->dpop->signingKey();
        $scope = 'atproto repo:app.bsky.feed.post repo:app.bsky.feed.like blob:*/*';

        $parEndpoint = (string) $metadata['pushed_authorization_request_endpoint'];
        $parForm = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $this->dpop->clientAssertion($issuer, $signingKey, $clientId),
        ];

        if ($identifier !== null && $identifier !== '') {
            $parForm['login_hint'] = $identifier;
        }

        $par = $this->postWithDpopNonce($parEndpoint, $key, $parForm);

        if ($par->failed() || ! is_string($par->json('request_uri'))) {
            Log::warning('Bluesky OAuth: PAR request failed', [
                'par_endpoint' => $parEndpoint,
                'status' => $par->status(),
                'body' => $par->body(),
                'identifier' => $identifier,
                'pds' => $pds,
                'issuer' => $issuer,
            ]);
            throw new RuntimeException('Bluesky OAuth could not start. Please try the app-password option for now.');
        }

        $authorizationEndpoint = (string) $metadata['authorization_endpoint'];
        $url = $authorizationEndpoint.'?'.http_build_query([
            'client_id' => $clientId,
            'request_uri' => $par->json('request_uri'),
        ]);

        return [
            'url' => $url,
            'state' => $state,
            'context' => [
                'identifier' => $identifier,
                'expected_did' => $did,
                'pds' => $pds,
                'issuer' => $issuer,
                'token_endpoint' => (string) $metadata['token_endpoint'],
                'code_verifier' => $verifier,
                'dpop_private_jwk' => $key,
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function callback(string $code, string $issuer, array $context): ConnectedAccountData
    {
        if ($issuer !== ($context['issuer'] ?? null)) {
            throw new RuntimeException('Bluesky returned from an unexpected authorization server.');
        }

        /** @var array<string, string> $key */
        $key = $context['dpop_private_jwk'];
        $tokenEndpoint = (string) $context['token_endpoint'];
        $signingKey = $this->dpop->signingKey();
        $response = $this->postWithDpopNonce($tokenEndpoint, $key, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => (string) $context['client_id'],
            'redirect_uri' => (string) $context['redirect_uri'],
            'code_verifier' => (string) $context['code_verifier'],
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $this->dpop->clientAssertion($issuer, $signingKey, (string) $context['client_id']),
        ]);

        if ($response->failed()) {
            Log::warning('Bluesky OAuth: token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'issuer' => $issuer,
            ]);
            throw new RuntimeException('Bluesky OAuth token exchange failed. Please try again. (HTTP '.$response->status().')');
        }

        $did = (string) $response->json('sub');
        if ($did === '' || ($context['expected_did'] && $did !== $context['expected_did'])) {
            throw new RuntimeException('Bluesky returned a different account than the one requested.');
        }

        $pds = (string) ($context['pds'] ?? 'https://bsky.social');
        if (! ($context['expected_did'] ?? null)) {
            $pds = $this->resolveDidToPds($did) ?? $pds;
        }

        $profile = $this->profile($did);
        $handle = (string) ($profile['handle'] ?? $context['identifier'] ?? $did);
        $expiresIn = (int) ($response->json('expires_in') ?? 0);

        return new ConnectedAccountData(
            platform: Platform::Bluesky,
            remoteAccountId: $did,
            handle: '@'.ltrim($handle, '@'),
            displayName: isset($profile['displayName']) ? (string) $profile['displayName'] : null,
            avatarUrl: isset($profile['avatar']) ? (string) $profile['avatar'] : null,
            authMethod: 'oauth',
            accessToken: (string) $response->json('access_token'),
            refreshToken: $response->json('refresh_token'),
            session: [
                'pds' => $pds,
                'auth_server' => (string) $context['issuer'],
                'issuer' => (string) $context['issuer'],
                'token_endpoint' => $tokenEndpoint,
                'client_id' => (string) $context['client_id'],
                'dpop_private_jwk' => $key,
                'dpop_nonce' => $response->header('DPoP-Nonce'),
            ],
            tokenExpiresAt: $expiresIn > 0 ? Date::now()->addSeconds($expiresIn)->toImmutable() : null,
        );
    }

    private function resolveDid(string $identifier): ?string
    {
        return $this->bluesky->resolveDid($identifier);
    }

    private function resolveDidToPds(string $did): ?string
    {
        $response = $this->http->timeout(5)->connectTimeout(3)->acceptJson()
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

    /**
     * @return array<string, mixed>
     */
    private function authorizationMetadata(string $pds): array
    {
        $resource = $this->http->timeout(5)->connectTimeout(3)->acceptJson()
            ->get($pds.'/.well-known/oauth-protected-resource');

        $authServer = $resource->json('authorization_servers.0');
        $endpoint = is_string($authServer) && $authServer !== ''
            ? rtrim($authServer, '/')
            : $pds;

        $response = $this->http->timeout(5)->connectTimeout(3)->acceptJson()
            ->get($endpoint.'/.well-known/oauth-authorization-server');

        if ($response->failed()) {
            throw new RuntimeException('Could not read Bluesky OAuth metadata.');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = (array) $response->json();

        foreach (['authorization_endpoint', 'token_endpoint', 'pushed_authorization_request_endpoint'] as $key) {
            if (! is_string($metadata[$key] ?? null) || $metadata[$key] === '') {
                throw new RuntimeException('Bluesky OAuth metadata is incomplete.');
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, string>  $key
     * @param  array<string, string>  $form
     */
    private function postWithDpopNonce(string $url, array $key, array $form, ?string $nonce = null): Response
    {
        try {
            $response = $this->http->asForm()
                ->withHeader('DPoP', $this->dpop->proof('POST', $url, $key, nonce: $nonce))
                ->post($url, $form);
        } catch (ConnectionException $e) {
            Log::warning('Bluesky OAuth: connection error during POST', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Could not reach the Bluesky authorization server. Please try again.');
        }

        $freshNonce = $response->header('DPoP-Nonce');
        if ($freshNonce === '') {
            $freshNonce = null;
        }

        if ($response->status() === 400 && $freshNonce !== null && $freshNonce !== $nonce && str_contains((string) $response->body(), 'use_dpop_nonce')) {
            return $this->http->asForm()
                ->withHeader('DPoP', $this->dpop->proof('POST', $url, $key, nonce: $freshNonce))
                ->post($url, $form);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $did): array
    {
        $response = $this->http->timeout(5)->connectTimeout(3)->acceptJson()
            ->get('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile', ['actor' => $did]);

        return $response->successful() ? (array) $response->json() : [];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
