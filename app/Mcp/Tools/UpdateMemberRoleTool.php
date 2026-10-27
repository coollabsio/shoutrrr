<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Http\Requests\Workspace\UpdateMemberRoleRequest;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_member_role')]
#[Description('Change a workspace member\'s role to member or admin. Cannot change the owner or your own role. Requires confirm=true.')]
class UpdateMemberRoleTool extends WorkspaceTool
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
            'membership_id' => ['required', 'string'],
            ...(new UpdateMemberRoleRequest)->rules(),
            'confirm' => ['boolean'],
        ]);

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($validated['membership_id'])
            ->with('user')
            ->first();

        if ($membership === null) {
            return Response::error('No member with that membership id exists in this workspace.');
        }

        if ($unconfirmed = $this->requireConfirmation($request, 'This will make '.$membership->user->email.' a workspace '.$validated['role'].'.')) {
            return $unconfirmed;
        }

        /** @var User $user */
        $user = $request->user();

        $members->updateRole($membership, $user, $validated['role']);

        return Response::text(json_encode([
            'membership_id' => $membership->id,
            'role' => $membership->role->value,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'membership_id' => $schema->string()->description('Membership id from list_members.')->required(),
            'role' => $schema->string()->enum(['member', 'admin'])->description('New role.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to change the role.'),
        ];
    }
}
