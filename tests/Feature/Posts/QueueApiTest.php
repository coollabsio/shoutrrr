<?php

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function queueMember(): array
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

function workspaceSchedule(Workspace $workspace, string $tz, array $slots): void
{
    $schedule = PostingSchedule::factory()->create([
        'workspace_id' => $workspace->id,
        'timezone' => $tz,
    ]);
    foreach ($slots as $i => [$weekday, $hour]) {
        PostingScheduleSlot::factory()->create([
            'posting_schedule_id' => $schedule->id,
            'weekday' => $weekday,
            'hour' => $hour,
            'position' => $i,
        ]);
    }
}

test('queueing schedules a draft into the next open slot', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00'); // Monday 08:30 UTC
    [$user, $workspace] = queueMember();
    workspaceSchedule($workspace, 'UTC', [[1, 9]]); // Monday 09:00

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft,
    ]);

    test()->postJson("/posts/{$post->id}/queue")
        ->assertOk()
        ->assertJsonPath('post.status', 'scheduled')
        ->assertJsonPath('post.scheduled_at', '2026-05-18T09:00:00+00:00');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Scheduled);
    expect($post->scheduled_at->toIso8601String())->toBe('2026-05-18T09:00:00+00:00');
});

test('queueing returns 422 when no open slot is available', function () {
    [$user, $workspace] = queueMember();
    // No posting schedule at all → resolver returns null.

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft,
    ]);

    test()->postJson("/posts/{$post->id}/queue")
        ->assertStatus(422)
        ->assertJsonPath('message', 'No open posting slot available. Add posting-schedule slots in settings.');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
});

test('queueing skips a slot already taken and uses the next', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    [$user, $workspace] = queueMember();
    workspaceSchedule($workspace, 'UTC', [[1, 9], [1, 11]]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft,
    ]);

    test()->postJson("/posts/{$post->id}/queue")
        ->assertOk()
        ->assertJsonPath('post.scheduled_at', '2026-05-18T11:00:00+00:00');
});

test('a member cannot queue a post in another workspace', function () {
    [$user, $workspace] = queueMember();
    $foreign = Post::factory()->create(); // different workspace

    test()->postJson("/posts/{$foreign->id}/queue")->assertNotFound();
});

test('queueing can use a selected open slot instead of the next one', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    [$user, $workspace] = queueMember();
    workspaceSchedule($workspace, 'UTC', [[1, 9], [1, 11]]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft,
    ]);

    test()->postJson("/posts/{$post->id}/queue", [
        'scheduled_at' => '2026-05-18T11:00:00+00:00',
    ])
        ->assertOk()
        ->assertJsonPath('post.scheduled_at', '2026-05-18T11:00:00+00:00');

    expect($post->refresh()->scheduled_at->toIso8601String())->toBe('2026-05-18T11:00:00+00:00');
});

test('queueing rejects a selected slot that is already occupied', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    [$user, $workspace] = queueMember();
    workspaceSchedule($workspace, 'UTC', [[1, 9], [1, 11]]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft,
    ]);

    test()->postJson("/posts/{$post->id}/queue", [
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Choose an open slot from your posting queue.');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
});

test('the next-slot endpoint includes open slots users can choose from', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    [$user, $workspace] = queueMember();
    workspaceSchedule($workspace, 'UTC', [[1, 9], [1, 11]]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    test()->getJson('/posts/next-slot')
        ->assertOk()
        ->assertJsonPath('slot', '2026-05-18T11:00:00+00:00')
        ->assertJsonPath('slots.0', '2026-05-18T11:00:00+00:00');
});
