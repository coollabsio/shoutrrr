<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkspaceInviteNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Member management shared by the settings page and MCP tools. Callers check
 * the `workspace.users.manage` permission and validate input first. Ownership
 * changes are deliberately not handled here.
 */
class WorkspaceMemberService
{
    /**
     * @throws ValidationException
     */
    public function invite(Workspace $workspace, User $inviter, string $email, string $role): WorkspaceInvitation
    {
        if ($workspace->members()->whereHas('user', fn ($q) => $q->where('email', $email))->exists()) {
            throw ValidationException::withMessages(['email' => 'This user is already a member.']);
        }

        [$plain, $hash] = WorkspaceInvitation::generateToken();

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'invited_by' => $inviter->id,
            'email' => $email,
            'role' => $role,
            'token' => $hash,
            'expires_at' => now()->addDays((int) config('kit.workspaces.invitation_ttl_days')),
        ]);

        $existingUser = User::query()->where('email', $invitation->email)->first();

        if ($existingUser !== null) {
            $existingUser->notify(new WorkspaceInviteNotification($invitation, $plain));
        } else {
            Notification::route('mail', $invitation->email)
                ->notify(new WorkspaceInviteNotification($invitation, $plain));
        }

        return $invitation;
    }

    /**
     * @throws ValidationException
     */
    public function updateRole(WorkspaceMembership $membership, User $actor, string $role): void
    {
        if ($membership->user_id === $actor->id) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        if ($membership->isOwner()) {
            throw ValidationException::withMessages(['role' => 'Use ownership transfer to change the owner.']);
        }

        $membership->update(['role' => $role]);
    }

    /**
     * @throws ValidationException
     */
    public function remove(WorkspaceMembership $membership, User $actor): void
    {
        if ($membership->isOwner()) {
            throw ValidationException::withMessages(['error' => 'Cannot remove the workspace owner.']);
        }

        if ($membership->user_id === $actor->id) {
            throw ValidationException::withMessages(['error' => 'Use “leave workspace” to remove yourself.']);
        }

        $member = $membership->user;
        $membership->delete();

        if ($member->current_workspace_id === $membership->workspace_id) {
            $next = $member->workspaceMemberships()->first();
            $member->forceFill(['current_workspace_id' => $next?->workspace_id])->save();
        }
    }
}
