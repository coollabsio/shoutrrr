<?php

use App\Enums\WorkspaceRole;
use App\Models\PostingSchedule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

function tzMember(WorkspaceRole $role): array
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

test('the overview page exposes the posting timezone and the timezone list', function () {
    [$user, $workspace] = tzMember(WorkspaceRole::Admin);
    PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'America/New_York']);

    test()->get(route('settings.workspace'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/overview')
            ->where('timezone', 'America/New_York')
            ->has('timezones'));
});

test('the overview timezone defaults to UTC when no schedule exists', function () {
    [$user, $workspace] = tzMember(WorkspaceRole::Member);

    test()->get(route('settings.workspace'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('timezone', 'UTC'));
});

test('an admin updates the posting timezone, creating the schedule', function () {
    [$user, $workspace] = tzMember(WorkspaceRole::Admin);

    test()->put(route('settings.workspace.timezone'), ['timezone' => 'Europe/London'])
        ->assertRedirect();

    $schedule = PostingSchedule::query()->where('workspace_id', $workspace->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->timezone)->toBe('Europe/London');
});

test('updating the timezone preserves existing slots', function () {
    [$user, $workspace] = tzMember(WorkspaceRole::Admin);
    $schedule = PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $schedule->slots()->create(['weekday' => 1, 'hour' => 9, 'minute' => 0, 'position' => 0]);

    test()->put(route('settings.workspace.timezone'), ['timezone' => 'Europe/London'])
        ->assertRedirect();

    expect($schedule->refresh()->timezone)->toBe('Europe/London');
    expect($schedule->slots()->count())->toBe(1);
});

test('an invalid timezone is rejected', function () {
    [$user, $workspace] = tzMember(WorkspaceRole::Admin);

    test()->from(route('settings.workspace'))
        ->put(route('settings.workspace.timezone'), ['timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors('timezone');
});

test('a plain member cannot update the timezone', function () {
    [$user, $workspace] = tzMember(WorkspaceRole::Member);

    test()->put(route('settings.workspace.timezone'), ['timezone' => 'Europe/London'])
        ->assertForbidden();
});
