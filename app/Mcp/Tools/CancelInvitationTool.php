<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\WorkspaceInvitation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('cancel_invitation')]
#[Description('Cancel a pending invitation to the bound workspace. The emailed link stops working.')]
class CancelInvitationTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.users.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'invitation_id' => ['required', 'string'],
        ]);

        $invitation = WorkspaceInvitation::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($validated['invitation_id'])
            ->first();

        if ($invitation === null) {
            return Response::error('No invitation with that id exists in this workspace.');
        }

        $invitation->delete();

        return Response::text(json_encode(['cancelled' => true, 'invitation_id' => $validated['invitation_id']], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'invitation_id' => $schema->string()->description('Invitation id from list_members.')->required(),
        ];
    }
}
