<?php

use App\Enums\WorkspaceRole;
use App\Jobs\FetchPostTargetReplies;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;

test('manual refresh dispatches a fetch job for the target', function (): void {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $workspace->id);

    $target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $workspace->id]))
        ->create(['remote_id' => 'at://root']);

    $this->actingAs($user)->post(route('engagement.refresh', $target))->assertRedirect();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});
