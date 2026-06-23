<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Dto\Workspace\InvitationAcceptanceResult;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkspaceInviteAcceptedNotification;
use Illuminate\Support\Facades\DB;

class WorkspaceInvitationService
{
    public function acceptByToken(string $plainToken, User $user): InvitationAcceptanceResult
    {
        $invitation = WorkspaceInvitation::findByToken($plainToken);

        if (! $invitation) {
            return new InvitationAcceptanceResult(false, 'Invalid invitation token.', 'error');
        }

        return $this->accept($invitation, $user);
    }

    public function accept(WorkspaceInvitation $invitation, User $user): InvitationAcceptanceResult
    {
        if (! $invitation->isValid()) {
            $message = $invitation->isExpired()
                ? 'This invitation has expired.'
                : 'This invitation has already been accepted.';

            return new InvitationAcceptanceResult(false, $message, 'error');
        }

        if (! hash_equals(mb_strtolower($invitation->email), mb_strtolower($user->email))) {
            return new InvitationAcceptanceResult(false, 'This invitation is for a different email address.', 'error');
        }

        if ($user->isMemberOfWorkspace($invitation->workspace_id)) {
            return new InvitationAcceptanceResult(false, 'You are already a member of this workspace.', 'warning');
        }

        DB::transaction(function () use ($invitation, $user): void {
            WorkspaceMembership::create([
                'workspace_id' => $invitation->workspace_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
            ]);

            $user->forceFill(['current_workspace_id' => $invitation->workspace_id]);

            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()]);
            }

            $user->save();
            $invitation->markAsAccepted();
        });

        $inviter = $invitation->inviter()->first();

        $inviter?->notify(new WorkspaceInviteAcceptedNotification($invitation, $user));

        return new InvitationAcceptanceResult(true, 'Successfully joined the workspace!', 'success');
    }
}
