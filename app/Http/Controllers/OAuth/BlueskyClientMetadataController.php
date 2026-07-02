<?php

declare(strict_types=1);

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Services\Atproto\DPoP;
use App\Services\ConnectedAccounts\BlueskyOAuthConnector;
use Illuminate\Http\JsonResponse;

class BlueskyClientMetadataController extends Controller
{
    public function __construct(private readonly DPoP $dpop) {}

    public function __invoke(): JsonResponse
    {
        $clientId = route('oauth.bluesky.metadata');

        return response()->json([
            'client_id' => $clientId,
            'application_type' => 'web',
            'client_name' => config('app.name', 'Shoutrrr'),
            'client_uri' => url('/'),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'scope' => BlueskyOAuthConnector::SCOPE,
            'response_types' => ['code'],
            'redirect_uris' => [route('accounts.bluesky.oauth.callback')],
            'dpop_bound_access_tokens' => true,
            'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => 'ES256',
            'jwks_uri' => route('oauth.bluesky.jwks'),
        ], 200, ['Content-Type' => 'application/json']);
    }

    public function jwks(): JsonResponse
    {
        return response()->json(
            $this->dpop->publicJwks($this->dpop->signingKey()),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
