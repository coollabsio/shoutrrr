<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Http\Requests\Workspace\InviteMemberRequest;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('invite_member')]
#[Description('Invite someone to the bound workspace by email as a member or admin. Sends an invitation email. Requires confirm=true.')]
class InviteMemberTool extends WorkspaceTool
{
    public function handle(Request $request, WorkspaceMemberService $members): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.users.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            ...(new InviteMemberRequest)->rules(),
            'confirm' => ['boolean'],
        ]);

        if ($unconfirmed = $this->requireConfirmation($request, 'This will email an invitation to '.$validated['email'].' to join as '.$validated['role'].'.')) {
            return $unconfirmed;
        }

        /** @var User $user */
        $user = $request->user();

        $invitation = $members->invite(Workspace::query()->findOrFail($workspaceId), $user, $validated['email'], $validated['role']);

        return Response::text(json_encode([
            'invitation_id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->description('Email address to invite.')->required(),
            'role' => $schema->string()->enum(['member', 'admin'])->description('Role the invitee gets on accepting.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to send the invitation email.'),
        ];
    }
}
