<?php

use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Inertia\Testing\AssertableInertia as Assert;

test('the compose page serializes target placements', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    $workspace->forceFill(['default_connected_account_id' => null])->save();

    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'handle' => '@ada',
        'connected_by_user_id' => $user->id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $user->id,
    ]);

    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'segment_breaks' => ['seg-1'],
    ]);

    $media = PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'post_id' => $post->id,
        'position' => 0,
    ]);

    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $media->id,
        'segment_ref' => 'seg-1',
        'position' => 0,
    ]);

    test()->actingAs($user)->get("/posts/{$post->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('compose/index')
            ->where('post.targets.0.placements.0.segment_ref', 'seg-1')
            ->where('post.targets.0.placements.0.media_id', $media->id)
            ->where('post.targets.0.segment_breaks', ['seg-1'])
            ->has('post.segment_breaks')
            ->where('post.segment_breaks', ['seg-1'])
            ->has('post.placements', 1),
        );
});
