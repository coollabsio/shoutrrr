<?php

use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => 'instagram',
    ]);
});

test('updating a draft persists the auto_repost override', function (): void {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'auto_repost' => null]);

    $response = $this->actingAs($this->user)->putJson(route('posts.update', $post), [
        'segments' => ['hello'],
        'destination' => ['kind' => 'account', 'id' => $this->account->id],
        'media_ids' => [],
        'auto_repost' => true,
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);

    $response->assertOk();
    expect($post->fresh()->auto_repost)->toBeTrue();
    expect($response->json('post.auto_repost'))->toBeTrue();
});

test('creating a draft persists an explicit auto_repost value', function (): void {
    $response = $this->actingAs($this->user)->postJson(route('posts.store'), [
        'segments' => ['hello'],
        'destination' => ['kind' => 'account', 'id' => $this->account->id],
        'auto_repost' => false,
    ]);

    $response->assertCreated();
    $postId = $response->json('post.id');
    expect(Post::withoutGlobalScopes()->findOrFail($postId)->auto_repost)->toBeFalse();
});
