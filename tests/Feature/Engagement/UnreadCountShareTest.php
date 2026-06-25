<?php

use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Inertia\Testing\AssertableInertia as Assert;

test('the shell exposes the unread reply count', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $workspace->id);
    $this->actingAs($user);

    $target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $workspace->id]))
        ->create();

    PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $workspace->id, 'read_at' => null, 'is_ours' => false,
    ]);
    PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $workspace->id, 'read_at' => now(), 'is_ours' => false,
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('shell.unreadReplies', 1));
});
