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

function nextSlotMember(): array
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

function nextSlotSchedule(Workspace $workspace, string $tz, array $slots): void
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

test('next-slot reports no schedule when none configured', function () {
    [$user, $workspace] = nextSlotMember();

    test()->getJson('/posts/next-slot')
        ->assertOk()
        ->assertJson([
            'has_schedule' => false,
            'slot' => null,
            'timezone' => 'UTC',
        ]);
});

test('next-slot returns the earliest free slot in UTC', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00'); // Monday 08:30 UTC
    [$user, $workspace] = nextSlotMember();
    nextSlotSchedule($workspace, 'UTC', [[1, 9]]); // Monday 09:00

    test()->getJson('/posts/next-slot')
        ->assertOk()
        ->assertJson([
            'has_schedule' => true,
            'slot' => '2026-05-18T09:00:00+00:00',
            'timezone' => 'UTC',
        ]);
});

test('next-slot reports full when every slot in the horizon is occupied', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    [$user, $workspace] = nextSlotMember();
    nextSlotSchedule($workspace, 'UTC', [[1, 9]]); // only Monday 09:00

    // Occupy every Monday-09:00 instant inside the 14-day horizon (2 Mondays).
    foreach (['2026-05-18T09:00:00+00:00', '2026-05-25T09:00:00+00:00'] as $at) {
        Post::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $at,
        ]);
    }

    test()->getJson('/posts/next-slot')
        ->assertOk()
        ->assertJson([
            'has_schedule' => true,
            'slot' => null,
            'timezone' => 'UTC',
        ]);
});

test('next-slot requires authentication', function () {
    test()->getJson('/posts/next-slot')->assertUnauthorized();
});
