<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\McpGrantWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Passport\AccessToken;

/**
 * Base for all workspace-scoped MCP tools. Resolves the workspace bound to the
 * caller's OAuth access token and installs the same `workspace_id` Context that
 * WorkspaceMiddleware sets for web requests, so model global-scopes and policies
 * behave identically. Also overrides the in-memory current_workspace_id so service
 * code that reads it operates on the bound workspace, not the user's last web choice.
 */
abstract class WorkspaceTool extends Tool
{
    /**
     * Resolve the workspace id bound to the current access token, or null.
     */
    protected function workspaceId(Request $request): ?string
    {
        $accessToken = $request->user()?->currentAccessToken();

        if (! $accessToken instanceof AccessToken) {
            return null;
        }

        $tokenId = $accessToken->oauth_access_token_id;

        return McpGrantWorkspace::where('access_token_id', $tokenId)->value('workspace_id');
    }

    /**
     * Install workspace scope for this tool call. Returns the workspace id, or null
     * if no binding exists (the tool should error).
     */
    protected function bindWorkspace(Request $request): ?string
    {
        $workspaceId = $this->workspaceId($request);

        if ($workspaceId === null) {
            return null;
        }

        /** @var User|null $user */
        $user = $request->user();

        // A token's grant must not outlive the user's membership: if the user has
        // been removed from (or left) the bound workspace, deny even though a stale
        // grant row still exists. The web path re-checks membership on every policy
        // call; this gives MCP the same guarantee.
        if ($user === null || ! $user->isMemberOfWorkspace($workspaceId)) {
            return null;
        }

        Context::add('workspace_id', $workspaceId);
        $user->current_workspace_id = $workspaceId; // in-memory only, not saved

        return $workspaceId;
    }

    /**
     * Authorize an ability against the bound user/workspace using the same policies
     * the web controllers use, so MCP write tools never grant more than the web UI.
     * Call after bindWorkspace(). Returns an error Response to return immediately on
     * denial, or null to proceed.
     */
    protected function authorize(Request $request, string $ability, Model|string $arguments): ?Response
    {
        $user = $request->user();

        if ($user === null || Gate::forUser($user)->denies($ability, $arguments)) {
            return Response::error('You do not have permission to perform this action in this workspace.');
        }

        return null;
    }

    /**
     * Guard an irreversible action behind an explicit confirm flag. Returns an error
     * Response to return immediately when unconfirmed, or null to proceed.
     */
    protected function requireConfirmation(Request $request, string $consequence): ?Response
    {
        if ($request->get('confirm') === true) {
            return null;
        }

        return Response::error(
            $consequence.' This action cannot be undone. Confirm with the human, then call again with confirm set to true.'
        );
    }

    /**
     * Same permission check the settings controllers use when there is no policy.
     * Call after bindWorkspace().
     */
    protected function requirePermission(Request $request, string $permission): ?Response
    {
        $user = $request->user();
        $workspaceId = Context::get('workspace_id');

        if (! $user instanceof User || ! is_string($workspaceId) || ! $user->hasAllPermissions([$permission], $workspaceId)) {
            return Response::error('You do not have permission to perform this action in this workspace.');
        }

        return null;
    }
}
