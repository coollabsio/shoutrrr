<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connect links handed to an agent (MCP or REST) carry a signed `workspace`
 * parameter. The OAuth callback stores the account in the session's current
 * workspace, so switch to the link's workspace first, then reload the same URL
 * without the signing parameters. Links without `workspace` (Classic buttons,
 * Agentic home) pass through unchanged.
 */
class SwitchWorkspaceFromConnectLink
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $request->query('workspace');

        if ($workspaceId === null) {
            return $next($request);
        }

        if (! is_string($workspaceId) || ! $request->hasValidRelativeSignature()) {
            abort(403, 'This connect link is invalid or has expired. Ask the agent for a new one.');
        }

        /** @var User $user */
        $user = $request->user();

        if (! $user->isMemberOfWorkspace($workspaceId)) {
            abort(403, 'You do not have access to this workspace.');
        }

        $user->forceFill(['current_workspace_id' => $workspaceId])->save();

        return redirect($request->fullUrlWithoutQuery(['workspace', 'expires', 'signature']));
    }
}
