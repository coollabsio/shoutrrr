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

test('the posting-schedule settings page renders', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    $schedule = PostingSchedule::factory()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'America/New_York',
    ]);
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => 1,
        'hour' => 9,
        'position' => 0,
    ]);

    test()->get(route('settings.posting-schedule'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/posting-schedule')
            ->where('timezone', 'America/New_York')
            ->where('canManage', true)
            ->has('slots', 1)
            ->where('slots.0.weekday', 1)
            ->where('slots.0.hour', 9));
});

test('the page renders with defaults when no schedule exists yet', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Member);

    test()->get(route('settings.posting-schedule'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/posting-schedule')
            ->where('timezone', 'UTC')
            ->where('canManage', false)
            ->has('slots', 0));
});

test('an admin replaces the whole slot set atomically', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    $schedule = PostingSchedule::factory()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'UTC',
    ]);
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => 5,
        'hour' => 22,
        'position' => 0,
    ]);

    test()->put(route('settings.posting-schedule.update'), [
        'timezone' => 'America/New_York',
        'slots' => [
            ['weekday' => 1, 'hour' => 9],
            ['weekday' => 3, 'hour' => 17],
        ],
    ])->assertRedirect();

    $schedule->refresh();
    expect($schedule->timezone)->toBe('America/New_York');

    $slots = $schedule->slots()->get();
    expect($slots)->toHaveCount(2);
    expect($slots->pluck('weekday')->all())->toBe([1, 3]);
    // old (5,22) slot is gone
    expect($slots->firstWhere('weekday', 5))->toBeNull();
});

test('updating creates the schedule when none exists', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Owner);

    test()->put(route('settings.posting-schedule.update'), [
        'timezone' => 'Europe/London',
        'slots' => [['weekday' => 0, 'hour' => 8]],
    ])->assertRedirect();

    $schedule = PostingSchedule::query()->where('workspace_id', $workspace->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->timezone)->toBe('Europe/London');
    expect($schedule->slots()->count())->toBe(1);
});

test('duplicate weekday+hour slots are de-duplicated', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    test()->put(route('settings.posting-schedule.update'), [
        'timezone' => 'UTC',
        'slots' => [
            ['weekday' => 2, 'hour' => 10],
            ['weekday' => 2, 'hour' => 10],
        ],
    ])->assertRedirect();

    $schedule = PostingSchedule::query()->where('workspace_id', $workspace->id)->first();
    expect($schedule->slots()->count())->toBe(1);
});

test('a plain member cannot edit slots', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Member);

    test()->put(route('settings.posting-schedule.update'), [
        'timezone' => 'UTC',
        'slots' => [['weekday' => 1, 'hour' => 9]],
    ])->assertForbidden();
});

test('invalid timezone is rejected', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    test()->from(route('settings.posting-schedule'))
        ->put(route('settings.posting-schedule.update'), [
            'timezone' => 'Mars/Olympus',
            'slots' => [],
        ])->assertSessionHasErrors('timezone');
});

test('out-of-range weekday is rejected', function () {
    [$user, $workspace] = scheduleMember(WorkspaceRole::Admin);

    test()->from(route('settings.posting-schedule'))
        ->put(route('settings.posting-schedule.update'), [
            'timezone' => 'UTC',
            'slots' => [['weekday' => 7, 'hour' => 9]],
        ])->assertSessionHasErrors('slots.0.weekday');
});
