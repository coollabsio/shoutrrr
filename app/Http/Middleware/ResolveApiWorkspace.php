<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP analogue of WorkspaceTool::bindWorkspace. Resolves the workspace bound to
 * the caller's API key, enforces active-key + live-membership, and installs the
 * same workspace_id Context the web path sets so model scopes and policies match.
 */
class ResolveApiWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $accessToken = $user?->currentAccessToken();

        if (! $accessToken instanceof AccessToken) {
            abort(401, 'Unauthenticated.');
        }

        $apiKey = ApiKey::query()
            ->where('access_token_id', $accessToken->oauth_access_token_id)
            ->first();

        if ($apiKey === null || ! $apiKey->isActive()) {
            abort(401, 'This API key is not valid.');
        }

        if (! $user->isMemberOfWorkspace($apiKey->workspace_id)) {
            abort(403, 'You are no longer a member of this workspace.');
        }

        Context::add('workspace_id', $apiKey->workspace_id);
        $user->current_workspace_id = $apiKey->workspace_id; // in-memory only

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
