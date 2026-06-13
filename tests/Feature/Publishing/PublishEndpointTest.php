<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Bus;

function publishingMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

test('publish-now sets the post publishing and dispatches targets', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Draft]);
    PostTarget::factory()->for($post)->create();

    test()->postJson("/posts/{$post->id}/publish")
        ->assertOk()
        ->assertJsonPath('post.status', 'publishing');

    expect($post->refresh()->status)->toBe(PostStatus::Publishing);
    Bus::assertDispatchedTimes(PublishPostTarget::class, 1);
});

test('publish-now is blocked across workspaces', function () {
    publishingMember();
    $foreign = Post::factory()->create();

    test()->postJson("/posts/{$foreign->id}/publish")->assertNotFound();
});

test('per-target retry resets a failed target to pending and dispatches it', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed]);
    $target = PostTarget::factory()->for($post)->failed()->create();

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertOk()
        ->assertJsonPath('post.id', $post->id);

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Pending)
        ->and($target->error_kind)->toBeNull()
        ->and($target->error_message)->toBeNull();

    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($target));
});

test('retry rejects a non-failed target with 409 and dispatches nothing', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Published]);
    $target = PostTarget::factory()->for($post)->published()->create();

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(409);

    expect($target->refresh()->status)->toBe(PostTargetStatus::Published);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('retry rejects a target belonging to another post', function () {
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    $otherTarget = PostTarget::factory()->create(); // different post + workspace

    test()->postJson("/posts/{$post->id}/targets/{$otherTarget->id}/retry")->assertNotFound();
});
