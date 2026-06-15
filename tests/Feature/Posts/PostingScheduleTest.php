<?php

use App\Enums\WorkspaceRole;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

function scheduleMember(WorkspaceRole $role): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

test('the queue page renders', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    $schedule = PostingSchedule::factory()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'America/New_York',
    ]);
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => 1,
        'hour' => 9,
        'minute' => 30,
        'position' => 0,
    ]);

    test()->get(route('queue.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('queue/index')
            ->where('timezone', 'America/New_York')
            ->where('canManage', true)
            ->has('slots', 1)
            ->where('slots.0.weekday', 1)
            ->where('slots.0.hour', 9)
            ->where('slots.0.minute', 30));
});

test('the queue page renders with defaults when no schedule exists yet', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Member);

    test()->get(route('queue.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('queue/index')
            ->where('timezone', 'UTC')
            ->where('canManage', false)
            ->has('slots', 0));
});

test('an admin replaces the whole slot set atomically, preserving timezone', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    $schedule = PostingSchedule::factory()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'America/New_York',
    ]);
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => 5, 'hour' => 22, 'minute' => 0, 'position' => 0,
    ]);

    test()->put(route('queue.update'), [
        'slots' => [
            ['weekday' => 1, 'hour' => 9, 'minute' => 30],
            ['weekday' => 3, 'hour' => 17, 'minute' => 0],
        ],
    ])->assertRedirect();

    $schedule->refresh();
    // timezone is untouched by queue.update (managed in workspace settings)
    expect($schedule->timezone)->toBe('America/New_York');

    $slots = $schedule->slots()->get();
    expect($slots)->toHaveCount(2);
    expect($slots->pluck('weekday')->all())->toBe([1, 3]);
    expect($slots->firstWhere('weekday', 1)->minute)->toBe(30);
    expect($slots->firstWhere('weekday', 5))->toBeNull();
});

test('updating creates the schedule when none exists, defaulting timezone to UTC', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Owner);

    test()->put(route('queue.update'), [
        'slots' => [['weekday' => 0, 'hour' => 8, 'minute' => 0]],
    ])->assertRedirect();

    $schedule = PostingSchedule::query()->where('workspace_id', $workspace->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->timezone)->toBe('UTC');
    expect($schedule->slots()->count())->toBe(1);
});

test('duplicate weekday+hour slots are de-duplicated', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    test()->put(route('queue.update'), [
        'slots' => [
            ['weekday' => 2, 'hour' => 10, 'minute' => 0],
            ['weekday' => 2, 'hour' => 10, 'minute' => 0],
        ],
    ])->assertRedirect();

    $schedule = PostingSchedule::query()->where('workspace_id', $workspace->id)->first();
    expect($schedule->slots()->count())->toBe(1);
});

test('a plain member cannot edit slots', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Member);

    test()->put(route('queue.update'), [
        'slots' => [['weekday' => 1, 'hour' => 9, 'minute' => 0]],
    ])->assertForbidden();
});

test('out-of-range weekday is rejected', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    test()->from(route('queue.show'))
        ->put(route('queue.update'), [
            'slots' => [['weekday' => 7, 'hour' => 9, 'minute' => 0]],
        ])->assertSessionHasErrors('slots.0.weekday');
});

test('out-of-range minute is rejected', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    test()->from(route('queue.show'))
        ->put(route('queue.update'), [
            'slots' => [['weekday' => 1, 'hour' => 9, 'minute' => 60]],
        ])->assertSessionHasErrors('slots.0.minute');
});
