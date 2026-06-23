<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\Workspace\WorkspaceInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceInvitationResponseController extends Controller
{
    public function accept(Request $request, WorkspaceInvitation $invitation, WorkspaceInvitationService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->abortUnlessInvitee($user, $invitation);

        $result = $service->accept($invitation, $user);

        if ($result->wasSuccessful()) {
            $this->deleteInvitationNotifications($user, $invitation);

            return redirect()->route('dashboard')->with('success', $result->message);
        }

        return back()->with($result->type, $result->message);
    }

    public function deny(Request $request, WorkspaceInvitation $invitation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->abortUnlessInvitee($user, $invitation);
        abort_unless($invitation->isValid(), 404);

        $this->deleteInvitationNotifications($user, $invitation);
        $invitation->delete();

        return back()->with('success', 'Invitation declined.');
    }

    private function abortUnlessInvitee(User $user, WorkspaceInvitation $invitation): void
    {
        abort_unless(hash_equals(mb_strtolower($invitation->email), mb_strtolower($user->email)), 404);
    }

    private function deleteInvitationNotifications(User $user, WorkspaceInvitation $invitation): void
    {
        $user->notifications()
            ->where('data->event', NotificationType::WorkspaceInvite->value)
            ->where('data->invitation_id', $invitation->id)
            ->delete();
    }
}
