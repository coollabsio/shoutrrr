<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_members')]
#[Description('List the members of the bound workspace with their roles, plus pending invitations. `can_manage` tells whether you may invite, change roles, remove members, or cancel invitations.')]
class ListMembersTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        /** @var User $user */
        $user = $request->user();

        $members = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->with('user')
            ->oldest()
            ->get()
            ->map(fn (WorkspaceMembership $membership): array => [
                'membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role->value,
                'is_owner' => $membership->isOwner(),
                'is_you' => $membership->user_id === $user->id,
                'joined_at' => $membership->created_at?->toIso8601String(),
            ])
            ->all();

        $invitations = WorkspaceInvitation::query()
            ->where('workspace_id', $workspaceId)
            ->pending()
            ->with('inviter')
            ->get()
            ->map(fn (WorkspaceInvitation $invitation): array => [
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'invited_by' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ])
            ->all();

        return Response::text(json_encode([
            'members' => $members,
            'pending_invitations' => $invitations,
            'can_manage' => $user->hasAllPermissions(['workspace.users.manage'], $workspaceId),
            'assignable_roles' => ['member', 'admin'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
