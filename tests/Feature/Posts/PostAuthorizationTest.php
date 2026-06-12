<?php

use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;

function memberOf(WorkspaceRole $role): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    return [$user, $workspace];
}

test('any member may view, create, update, and delete drafts in their workspace', function () {
    [$member, $workspace] = memberOf(WorkspaceRole::Member);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $member->id]);

    expect($member->can('viewAny', Post::class))->toBeTrue()
        ->and($member->can('create', Post::class))->toBeTrue()
        ->and($member->can('update', $post))->toBeTrue()
        ->and($member->can('delete', $post))->toBeTrue();
});

test('a user cannot touch a post in another workspace', function () {
    [$member] = memberOf(WorkspaceRole::Member);
    $otherWorkspace = Workspace::factory()->create();
    $foreign = Post::factory()->create(['workspace_id' => $otherWorkspace->id]);

    expect($member->can('update', $foreign))->toBeFalse()
        ->and($member->can('delete', $foreign))->toBeFalse();
});
