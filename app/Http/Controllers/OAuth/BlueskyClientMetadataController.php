<?php

declare(strict_types=1);

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BlueskyClientMetadataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $clientId = route('oauth.bluesky.metadata');

        return response()->json([
            'client_id' => $clientId,
            'application_type' => 'web',
            'client_name' => config('app.name', 'Shoutrrr'),
            'client_uri' => url('/'),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'scope' => 'atproto transition:generic',
            'response_types' => ['code'],
            'redirect_uris' => [route('accounts.bluesky.oauth.callback')],
            'dpop_bound_access_tokens' => true,
            'token_endpoint_auth_method' => 'none',
        ], 200, ['Content-Type' => 'application/json']);
    }
}
