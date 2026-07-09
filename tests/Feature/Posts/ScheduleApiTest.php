<?php

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

function schedulingMember(): array
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

test('PUT /posts/{post}/schedule schedules a draft', function () {
    [$user, $workspace] = schedulingMember();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft,
    ]);

    $response = test()->putJson("/posts/{$post->id}/schedule", [
        'scheduled_at' => '2030-01-01T09:00:00+00:00',
    ]);

    $response->assertOk()
        ->assertJsonPath('post.status', 'scheduled')
        ->assertJsonPath('post.scheduled_at', '2030-01-01T09:00:00+00:00');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Scheduled);
    expect($post->scheduled_at)->not->toBeNull();
});

test('PUT /posts/{post}/schedule unschedules when scheduled_at is null', function () {
    [$user, $workspace] = schedulingMember();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    $response = test()->putJson("/posts/{$post->id}/schedule", [
        'scheduled_at' => null,
    ]);

    $response->assertOk()
        ->assertJsonPath('post.status', 'draft')
        ->assertJsonPath('post.scheduled_at', null);

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Draft);
    expect($post->scheduled_at)->toBeNull();
});

test('PUT /posts/{post}/schedule rejects a time in the past', function () {
    [$user, $workspace] = schedulingMember();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft,
    ]);

    test()->putJson("/posts/{$post->id}/schedule", [
        'scheduled_at' => now()->subHour()->toIso8601String(),
    ])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    expect($post->scheduled_at)->toBeNull();
});

test('PUT /posts/{post}/schedule reschedules a missed post back to scheduled', function () {
    [$user, $workspace] = schedulingMember();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Missed,
        'scheduled_at' => now()->subDays(3),
    ]);

    test()->putJson("/posts/{$post->id}/schedule", [
        'scheduled_at' => '2030-01-01T09:00:00+00:00',
    ])->assertOk()->assertJsonPath('post.status', 'scheduled');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Scheduled);
    expect($post->scheduled_at->toIso8601String())->toBe('2030-01-01T09:00:00+00:00');
});

test('PUT /posts/{post}/schedule redirects after an Inertia reschedule request', function () {
    [$user, $workspace] = schedulingMember();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    test()->from('/dashboard')
        ->put("/posts/{$post->id}/schedule", [
            'scheduled_at' => '2030-01-01T09:00:00+00:00',
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'test',
        ])
        ->assertRedirect('/dashboard');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Scheduled);
    expect($post->scheduled_at->toIso8601String())->toBe('2030-01-01T09:00:00+00:00');
});

test('PUT /posts/{post}/schedule returns 404 when the user has no current workspace', function () {
    [$user, $workspace] = schedulingMember();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft,
    ]);
    $user->forceFill(['current_workspace_id' => null])->save();

    test()->putJson("/posts/{$post->id}/schedule", [
        'scheduled_at' => '2030-01-01T09:00:00+00:00',
    ])->assertNotFound();

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
});

test('a member cannot schedule a post in another workspace', function () {
    [$user, $workspace] = schedulingMember();
    $foreign = Post::factory()->create(); // different workspace

    test()->putJson("/posts/{$foreign->id}/schedule", [
        'scheduled_at' => '2030-01-01T09:00:00+00:00',
    ])->assertNotFound();
});
