<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

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

#[Name('remove_member')]
#[Description('Remove a member from the bound workspace. They lose access immediately. Cannot remove the owner or yourself. Irreversible. Requires confirm=true.')]
class RemoveMemberTool extends WorkspaceTool
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

        if ($unconfirmed = $this->requireConfirmation($request, 'This will remove '.$membership->user->email.' from the workspace.')) {
            return $unconfirmed;
        }

        /** @var User $user */
        $user = $request->user();

        $members->remove($membership, $user);

        return Response::text(json_encode(['removed' => true, 'membership_id' => $membership->id], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'membership_id' => $schema->string()->description('Membership id from list_members.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to remove the member.'),
        ];
    }
}
