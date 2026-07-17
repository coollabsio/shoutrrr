<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\InstanceSettings;
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

test('per-target retry redirects after an Inertia retry request', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed]);
    $target = PostTarget::factory()->for($post)->failed()->create();

    test()->from('/dashboard')
        ->post("/posts/{$post->id}/targets/{$target->id}/retry", [], [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'test',
        ])
        ->assertRedirect('/dashboard');

    expect($target->refresh()->status)->toBe(PostTargetStatus::Pending);
    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($target));
});

test('per-target retry resets a skipped target to pending and dispatches it', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Partial]);
    $target = PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Skipped->value,
        'error_message' => 'X is disabled on this instance.',
    ]);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertOk()
        ->assertJsonPath('post.id', $post->id);

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Pending)
        ->and($target->error_kind)->toBeNull()
        ->and($target->error_message)->toBeNull();

    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($target));
});

test('retrying a skipped target whose platform is still frozen re-skips it instead of erroring', function () {
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Partial]);
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'status' => PostTargetStatus::Skipped->value,
        'error_message' => 'X is disabled on this instance.',
    ]);

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    // Sync queue runs the retried job inline, so the terminal + freeze guards in
    // PublishPostTarget::handle() execute within this request.
    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertOk();

    expect($target->fresh()->status)->toBe(PostTargetStatus::Skipped);
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

test('publish-now is blocked (422) when a target is over the limit and does not dispatch', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Draft]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky->value,
        'sections' => [str_repeat('x', 400)],
        'auto_split' => false,
    ]);

    test()->postJson("/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('blocked.0.platform', 'bluesky')
        ->assertJsonPath('blocked.0.issues.0', 'section_too_long');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Bus::assertNotDispatched(PublishPostTarget::class);
});
