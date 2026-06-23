<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkspaceInviteNotification;
use App\Support\Notifications\NotificationPresenter;
use Illuminate\Support\Str;

function inviteeWithWorkspaceInvitation(): array
{
    $workspace = Workspace::factory()->create();
    $currentWorkspace = Workspace::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'guest@example.com',
        'current_workspace_id' => $currentWorkspace->id,
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $currentWorkspace->id,
        'user_id' => $invitee->id,
    ]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => $invitee->email,
    ]);
    $notificationId = (string) Str::uuid();

    $invitee->notifications()->create([
        'id' => $notificationId,
        'type' => WorkspaceInviteNotification::class,
        'data' => [
            'event' => NotificationType::WorkspaceInvite->value,
            'title' => 'Workspace invitation',
            'body' => $workspace->name.' invited you to collaborate.',
            'href' => null,
            'icon' => 'users',
            'workspace_id' => null,
            'invited_workspace_id' => $workspace->id,
            'invitation_id' => $invitation->id,
        ],
        'read_at' => null,
    ]);

    return [$invitee, $workspace, $invitation, $notificationId];
}

test('workspace invite notifications include accept and deny actions', function () {
    [$invitee, , $invitation] = inviteeWithWorkspaceInvitation();

    $item = NotificationPresenter::item($invitee->notifications()->first());

    expect($item['actions'])->toBe([
        [
            'key' => 'accept',
            'label' => 'Accept',
            'variant' => 'primary',
            'method' => 'post',
            'href' => route('workspace.invitations.accept', $invitation, absolute: false),
        ],
        [
            'key' => 'deny',
            'label' => 'Deny',
            'variant' => 'secondary',
            'method' => 'delete',
            'href' => route('workspace.invitations.deny', $invitation, absolute: false),
        ],
    ]);
});

test('invitee can accept a workspace invitation from a notification action', function () {
    [$invitee, $workspace, $invitation, $notificationId] = inviteeWithWorkspaceInvitation();

    $this->actingAs($invitee)
        ->post(route('workspace.invitations.accept', $invitation))
        ->assertRedirect(route('dashboard'));

    expect($invitee->fresh()->isMemberOfWorkspace($workspace->id))->toBeTrue()
        ->and($invitee->fresh()->current_workspace_id)->toBe($workspace->id)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitee->notifications()->find($notificationId))->toBeNull();
});

test('invitee can deny a workspace invitation from a notification action', function () {
    [$invitee, , $invitation, $notificationId] = inviteeWithWorkspaceInvitation();

    $this->actingAs($invitee)
        ->delete(route('workspace.invitations.deny', $invitation))
        ->assertRedirect();

    expect(WorkspaceInvitation::find($invitation->id))->toBeNull()
        ->and($invitee->notifications()->find($notificationId))->toBeNull();
});

test('another user cannot answer someone elses invitation notification', function () {
    [, , $invitation] = inviteeWithWorkspaceInvitation();
    $other = User::factory()->create(['email' => 'other@example.com']);

    $this->actingAs($other)
        ->post(route('workspace.invitations.accept', $invitation))
        ->assertNotFound();

    expect(WorkspaceMembership::where('workspace_id', $invitation->workspace_id)->exists())->toBeFalse()
        ->and($invitation->fresh())->not->toBeNull();
});
