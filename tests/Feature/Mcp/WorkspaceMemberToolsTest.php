<?php

use App\Enums\WorkspaceRole;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CancelInvitationTool;
use App\Mcp\Tools\InviteMemberTool;
use App\Mcp\Tools\ListMembersTool;
use App\Mcp\Tools\RemoveMemberTool;
use App\Mcp\Tools\UpdateMemberRoleTool;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkspaceInviteNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * @return array<string, mixed>
 */
function memberToolJson(TestResponse $response): array
{
    $payload = (fn (): array => $this->response->toArray())->call($response);

    return json_decode($payload['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

function addWorkspaceMember(Workspace $workspace, WorkspaceRole $role = WorkspaceRole::Member, array $attributes = []): WorkspaceMembership
{
    return WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => User::factory()->create($attributes)->id,
        'role' => $role,
    ]);
}

/**
 * @return array{0: User, 1: Workspace}
 */
function plainMemberActor(): array
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    bindTokenToWorkspace($user, $workspace);

    return [$user, $workspace];
}

const MEMBER_TOOLS_DENIED = 'You do not have permission to perform this action in this workspace.';

test('list_members returns members with roles and pending invitations only', function (): void {
    [$owner, $workspace] = mcpOwner();
    $owner->forceFill(['name' => 'Olive Owner', 'email' => 'olive@example.com'])->save();
    $admin = addWorkspaceMember($workspace, WorkspaceRole::Admin, ['name' => 'Ada Admin', 'email' => 'ada@example.com']);
    $pending = WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'invited_by' => $owner->id, 'email' => 'pending@example.com', 'role' => 'member']);
    WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $workspace->id, 'email' => 'expired@example.com']);
    addWorkspaceMember(Workspace::factory()->create(), WorkspaceRole::Admin, ['email' => 'foreign@example.com']);

    $json = memberToolJson(ShoutrrrServer::actingAs($owner)->tool(ListMembersTool::class, []));

    $members = collect($json['members'])->keyBy('email');
    expect($members->keys()->sort()->values()->all())->toBe(['ada@example.com', 'olive@example.com'])
        ->and($members['olive@example.com'])->toMatchArray(['name' => 'Olive Owner', 'role' => 'owner', 'is_owner' => true, 'is_you' => true])
        ->and($members['ada@example.com'])->toMatchArray(['membership_id' => $admin->id, 'name' => 'Ada Admin', 'role' => 'admin', 'is_owner' => false, 'is_you' => false])
        ->and($json['pending_invitations'])->toHaveCount(1)
        ->and($json['pending_invitations'][0])->toMatchArray(['invitation_id' => $pending->id, 'email' => 'pending@example.com', 'role' => 'member', 'invited_by' => 'Olive Owner'])
        ->and($json['can_manage'])->toBeTrue()
        ->and($json['assignable_roles'])->toBe(['member', 'admin']);
});

test('a plain member can list members but cannot manage', function (): void {
    [$user] = plainMemberActor();

    $json = memberToolJson(ShoutrrrServer::actingAs($user)->tool(ListMembersTool::class, []));

    expect($json['can_manage'])->toBeFalse()
        ->and($json['members'])->toHaveCount(1);
});

test('invite_member requires confirm, then creates the invitation and emails it', function (): void {
    Notification::fake();
    [$owner, $workspace] = mcpOwner();

    ShoutrrrServer::actingAs($owner)->tool(InviteMemberTool::class, ['email' => 'new@example.com', 'role' => 'admin'])
        ->assertHasErrors(['This will email an invitation to new@example.com to join as admin.']);
    expect(WorkspaceInvitation::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $response = ShoutrrrServer::actingAs($owner)->tool(InviteMemberTool::class, ['email' => 'new@example.com', 'role' => 'admin', 'confirm' => true]);
    $response->assertOk();

    $invitation = WorkspaceInvitation::query()->sole();
    expect($invitation->workspace_id)->toBe($workspace->id)
        ->and($invitation->email)->toBe('new@example.com')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->invited_by)->toBe($owner->id)
        ->and(memberToolJson($response)['invitation_id'])->toBe($invitation->id);
    Notification::assertSentOnDemand(
        WorkspaceInviteNotification::class,
        fn (WorkspaceInviteNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'new@example.com',
    );
});

test('invite_member refuses an existing member, an owner role, and a duplicate pending invite', function (): void {
    Notification::fake();
    [$owner, $workspace] = mcpOwner();
    addWorkspaceMember($workspace, attributes: ['email' => 'already@example.com']);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'email' => 'pending@example.com']);

    ShoutrrrServer::actingAs($owner)->tool(InviteMemberTool::class, ['email' => 'already@example.com', 'role' => 'member', 'confirm' => true])
        ->assertHasErrors(['This user is already a member.']);
    ShoutrrrServer::actingAs($owner)->tool(InviteMemberTool::class, ['email' => 'x@example.com', 'role' => 'owner', 'confirm' => true])
        ->assertHasErrors();
    ShoutrrrServer::actingAs($owner)->tool(InviteMemberTool::class, ['email' => 'pending@example.com', 'role' => 'member', 'confirm' => true])
        ->assertHasErrors();

    expect(WorkspaceInvitation::query()->pluck('email')->all())->toBe(['pending@example.com']);
    Notification::assertNothingSent();
});

test('update_member_role requires confirm and changes the role', function (): void {
    [$owner, $workspace] = mcpOwner();
    $membership = addWorkspaceMember($workspace, attributes: ['email' => 'm@example.com']);

    ShoutrrrServer::actingAs($owner)->tool(UpdateMemberRoleTool::class, ['membership_id' => $membership->id, 'role' => 'admin'])
        ->assertHasErrors(['This will make m@example.com a workspace admin.']);
    expect($membership->fresh()->role)->toBe(WorkspaceRole::Member);

    $response = ShoutrrrServer::actingAs($owner)->tool(UpdateMemberRoleTool::class, ['membership_id' => $membership->id, 'role' => 'admin', 'confirm' => true]);

    expect(memberToolJson($response))->toBe(['membership_id' => $membership->id, 'role' => 'admin'])
        ->and($membership->fresh()->role)->toBe(WorkspaceRole::Admin);
});

test('update_member_role never touches the owner, your own role, or grants owner', function (): void {
    [$owner, $workspace] = mcpOwner();
    $admin = addWorkspaceMember($workspace, WorkspaceRole::Admin);
    $adminUser = $admin->user;
    $adminUser->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($adminUser, $workspace);
    $ownerMembership = $owner->getMembershipForWorkspace($workspace->id);
    $member = addWorkspaceMember($workspace);

    ShoutrrrServer::actingAs($adminUser)->tool(UpdateMemberRoleTool::class, ['membership_id' => $ownerMembership->id, 'role' => 'member', 'confirm' => true])
        ->assertHasErrors(['Use ownership transfer to change the owner.']);
    ShoutrrrServer::actingAs($adminUser)->tool(UpdateMemberRoleTool::class, ['membership_id' => $admin->id, 'role' => 'member', 'confirm' => true])
        ->assertHasErrors(['You cannot change your own role.']);
    ShoutrrrServer::actingAs($adminUser)->tool(UpdateMemberRoleTool::class, ['membership_id' => $member->id, 'role' => 'owner', 'confirm' => true])
        ->assertHasErrors();

    expect($ownerMembership->fresh()->role)->toBe(WorkspaceRole::Owner)
        ->and($admin->fresh()->role)->toBe(WorkspaceRole::Admin)
        ->and($member->fresh()->role)->toBe(WorkspaceRole::Member);
});

test('remove_member requires confirm, removes the member, and moves their current workspace', function (): void {
    [$owner, $workspace] = mcpOwner();
    $membership = addWorkspaceMember($workspace, attributes: ['email' => 'gone@example.com']);
    $removed = $membership->user;
    $other = Workspace::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $removed->id]);
    $removed->forceFill(['current_workspace_id' => $workspace->id])->save();

    ShoutrrrServer::actingAs($owner)->tool(RemoveMemberTool::class, ['membership_id' => $membership->id])
        ->assertHasErrors(['This will remove gone@example.com from the workspace.']);
    expect(WorkspaceMembership::query()->find($membership->id))->not->toBeNull();

    ShoutrrrServer::actingAs($owner)->tool(RemoveMemberTool::class, ['membership_id' => $membership->id, 'confirm' => true])->assertOk();

    expect(WorkspaceMembership::query()->find($membership->id))->toBeNull()
        ->and($removed->fresh()->current_workspace_id)->toBe($other->id);
});

test('remove_member refuses the owner and yourself', function (): void {
    [$owner, $workspace] = mcpOwner();
    $ownerMembership = $owner->getMembershipForWorkspace($workspace->id);
    $admin = addWorkspaceMember($workspace, WorkspaceRole::Admin);
    $admin->user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($admin->user, $workspace);

    ShoutrrrServer::actingAs($admin->user)->tool(RemoveMemberTool::class, ['membership_id' => $ownerMembership->id, 'confirm' => true])
        ->assertHasErrors(['Cannot remove the workspace owner.']);
    ShoutrrrServer::actingAs($admin->user)->tool(RemoveMemberTool::class, ['membership_id' => $admin->id, 'confirm' => true])
        ->assertHasErrors(['Use “leave workspace” to remove yourself.']);

    expect(WorkspaceMembership::query()->whereKey([$ownerMembership->id, $admin->id])->count())->toBe(2);
});

test('cancel_invitation deletes a pending invitation', function (): void {
    [$owner, $workspace] = mcpOwner();
    $invitation = WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);

    ShoutrrrServer::actingAs($owner)->tool(CancelInvitationTool::class, ['invitation_id' => $invitation->id])->assertOk();

    expect(WorkspaceInvitation::query()->find($invitation->id))->toBeNull();
});

test('a plain member is denied every member-management tool', function (): void {
    Notification::fake();
    [$user, $workspace] = plainMemberActor();
    $other = addWorkspaceMember($workspace);
    $invitation = WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);

    ShoutrrrServer::actingAs($user)->tool(InviteMemberTool::class, ['email' => 'n@example.com', 'role' => 'member', 'confirm' => true])->assertHasErrors([MEMBER_TOOLS_DENIED]);
    ShoutrrrServer::actingAs($user)->tool(UpdateMemberRoleTool::class, ['membership_id' => $other->id, 'role' => 'admin', 'confirm' => true])->assertHasErrors([MEMBER_TOOLS_DENIED]);
    ShoutrrrServer::actingAs($user)->tool(RemoveMemberTool::class, ['membership_id' => $other->id, 'confirm' => true])->assertHasErrors([MEMBER_TOOLS_DENIED]);
    ShoutrrrServer::actingAs($user)->tool(CancelInvitationTool::class, ['invitation_id' => $invitation->id])->assertHasErrors([MEMBER_TOOLS_DENIED]);

    expect($other->fresh()->role)->toBe(WorkspaceRole::Member)
        ->and(WorkspaceInvitation::query()->pluck('id')->all())->toBe([$invitation->id]);
    Notification::assertNothingSent();
});

test('member tools cannot reach another workspace\'s members or invitations', function (): void {
    [$owner] = mcpOwner();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignMember = addWorkspaceMember($foreignWorkspace);
    $foreignInvitation = WorkspaceInvitation::factory()->create(['workspace_id' => $foreignWorkspace->id]);

    ShoutrrrServer::actingAs($owner)->tool(UpdateMemberRoleTool::class, ['membership_id' => $foreignMember->id, 'role' => 'admin', 'confirm' => true])
        ->assertHasErrors(['No member with that membership id exists in this workspace.']);
    ShoutrrrServer::actingAs($owner)->tool(RemoveMemberTool::class, ['membership_id' => $foreignMember->id, 'confirm' => true])
        ->assertHasErrors(['No member with that membership id exists in this workspace.']);
    ShoutrrrServer::actingAs($owner)->tool(CancelInvitationTool::class, ['invitation_id' => $foreignInvitation->id])
        ->assertHasErrors(['No invitation with that id exists in this workspace.']);

    expect($foreignMember->fresh()->role)->toBe(WorkspaceRole::Member)
        ->and(WorkspaceInvitation::query()->find($foreignInvitation->id))->not->toBeNull();
});
