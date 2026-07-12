<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

test('permission helpers resolve from membership role', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Admin,
    ]);

    $this->assertTrue($user->hasAllPermissions(['workspace.users.manage'], $workspace->id));
    $this->assertFalse($user->hasAllPermissions(['workspace.delete'], $workspace->id));
    $this->assertFalse($user->isOwnerOfWorkspace($workspace->id));
    $this->assertTrue($user->isMemberOfWorkspace($workspace->id));
});

test('helpers are false without membership', function () {
    $user = User::factory()->create();

    $this->assertFalse($user->hasAllPermissions(['workspace.read'], '00000000-0000-0000-0000-000000000000'));
    $this->assertFalse($user->isMemberOfWorkspace(null));
});
