<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\TransferOwnershipRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\Workspace\WorkspaceInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $user = $request->user();

        /** @var User $user */
        DB::transaction(function () use ($request, $user): void {
            $workspace = Workspace::create([
                'name' => $request->validated('name'),
                'slug' => $this->uniqueSlug($request->validated('name')),
                'owner_id' => $user->id,
            ]);

            WorkspaceMembership::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => WorkspaceRole::Owner,
            ]);

            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        });

        return redirect()->route('dashboard')->with('success', 'Workspace created successfully.');
    }

    public function switch(Request $request): RedirectResponse
    {
        $request->validate(['workspace_id' => ['required', 'string']]);

        $user = $request->user();

        /** @var User $user */
        if (! $user->isMemberOfWorkspace($request->input('workspace_id'))) {
            return back()->withErrors(['workspace_id' => 'You do not have access to this workspace.']);
        }

        $user->forceFill(['current_workspace_id' => $request->input('workspace_id')])->save();

        return redirect()->route('dashboard')->with('success', 'Workspace switched.');
    }

    public function leave(Request $request, Workspace $workspace): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $user->getMembershipForWorkspace($workspace->id);

        if (! $membership) {
            abort(404);
        }

        if ($this->isSoleOwnerWithOtherMembers($workspace, $user->id)) {
            return back()->withErrors(['workspace' => 'Transfer ownership or delete the workspace before leaving.']);
        }

        DB::transaction(function () use ($membership, $user, $workspace): void {
            $membership->delete();

            if ($user->current_workspace_id === $workspace->id) {
                $next = $user->workspaceMemberships()->first();
                $user->forceFill(['current_workspace_id' => $next?->workspace_id])->save();
            }
        });

        return redirect()->route('dashboard')->with('success', 'You left the workspace.');
    }

    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isOwnerOfWorkspace($workspace->id)) {
            abort(403);
        }

        DB::transaction(function () use ($workspace): void {
            // Reassign current workspace for any member who had this as their current,
            // BEFORE the nullOnDelete FK cascade fires, so they land on another of their
            // workspaces when one exists (otherwise it becomes null).
            $affected = User::where('current_workspace_id', $workspace->id)->get();

            foreach ($affected as $member) {
                $next = $member->workspaceMemberships()
                    ->where('workspace_id', '!=', $workspace->id)
                    ->first();

                $member->forceFill(['current_workspace_id' => $next?->workspace_id])->save();
            }

            $workspace->delete(); // cascades memberships + invitations via FK
        });

        return redirect()->route('dashboard')->with('success', 'Workspace deleted.');
    }

    public function transferOwnership(TransferOwnershipRequest $request, Workspace $workspace): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isOwnerOfWorkspace($workspace->id)) {
            abort(403);
        }

        $target = WorkspaceMembership::where('id', $request->validated('membership_id'))
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        $currentOwner = $user->getMembershipForWorkspace($workspace->id);

        abort_if($currentOwner === null, 403);

        DB::transaction(function () use ($workspace, $target, $currentOwner): void {
            $target->update(['role' => WorkspaceRole::Owner]);
            $currentOwner->update(['role' => WorkspaceRole::Admin]);
            $workspace->update(['owner_id' => $target->user_id]);
        });

        return back()->with('success', 'Ownership transferred.');
    }

    public function showInvitation(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isValid()) {
            return redirect()->route('home')->withErrors([
                'invitation' => 'This invitation link is invalid or has expired.',
            ]);
        }

        $user = $request->user();

        if ($user) {
            /** @var User $user */
            $result = app(WorkspaceInvitationService::class)->accept($invitation, $user);

            return $result->wasSuccessful()
                ? redirect()->route('dashboard')->with('success', $result->message)
                : redirect()->route('dashboard')->with('error', $result->message);
        }

        return Inertia::render('auth/workspace-invitation', [
            'invitation' => [
                'token' => $token,
                'workspace_name' => $invitation->workspace->name,
                'role' => $invitation->role,
                'inviter_name' => $invitation->inviter()->value('name') ?? 'Someone',
                'expires_at' => $invitation->expires_at,
            ],
            'userExists' => User::where('email', $invitation->email)->exists(),
            'loginUrl' => route('login', ['invitation' => $token]),
            'registerUrl' => route('register', ['invitation' => $token]),
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        do {
            $slug = Str::slug($name).'-'.Str::lower(Str::random(5));
        } while (Workspace::where('slug', $slug)->exists());

        return $slug;
    }

    private function isSoleOwnerWithOtherMembers(Workspace $workspace, string $userId): bool
    {
        $owners = WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('role', WorkspaceRole::Owner->value)
            ->pluck('user_id');

        $totalMembers = WorkspaceMembership::where('workspace_id', $workspace->id)->count();

        return $owners->count() === 1 && $owners->first() === $userId && $totalMembers > 1;
    }
}
