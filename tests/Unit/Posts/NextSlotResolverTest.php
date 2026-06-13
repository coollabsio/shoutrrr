<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\Workspace;
use App\Services\Posts\NextSlotResolver;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function scheduleWith(Workspace $workspace, string $tz, array $slots): PostingSchedule
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

    return $schedule;
}

test('returns null when the workspace has no posting schedule', function () {
    $workspace = Workspace::factory()->create();

    expect(app(NextSlotResolver::class)->resolve($workspace))->toBeNull();
});

test('returns null when the schedule has no slots', function () {
    $workspace = Workspace::factory()->create();
    PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    expect(app(NextSlotResolver::class)->resolve($workspace))->toBeNull();
});

test('picks the earliest future slot in UTC', function () {
    // 2026-05-18 is a Monday. weekday Sun=0 → Monday=1. Slot Mon 09:00 UTC.
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'UTC', [[1, 9], [3, 15]]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot)->not->toBeNull();
    expect($slot->toIso8601String())->toBe('2026-05-18T09:00:00+00:00');
});

test('a slot exactly at now is skipped (strictly future)', function () {
    CarbonImmutable::setTestNow('2026-05-18T09:00:00+00:00');
    $workspace = Workspace::factory()->create();
    // Only slot is Mon 09:00 — equal to now, so it must wrap to next Monday.
    scheduleWith($workspace, 'UTC', [[1, 9]]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot->toIso8601String())->toBe('2026-05-25T09:00:00+00:00');
});

test('skips a slot already occupied by a scheduled post', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'UTC', [[1, 9], [1, 11]]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot->toIso8601String())->toBe('2026-05-18T11:00:00+00:00');
});

test('a publishing post also occupies its slot', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'UTC', [[1, 9], [1, 11]]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Publishing,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot->toIso8601String())->toBe('2026-05-18T11:00:00+00:00');
});

test('a non-occupying status (draft/published) does not block the slot', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'UTC', [[1, 9]]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Published,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot->toIso8601String())->toBe('2026-05-18T09:00:00+00:00');
});

test('an occupied slot in another workspace does not block this workspace', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'UTC', [[1, 9]]);

    $other = Workspace::factory()->create();
    Post::factory()->create([
        'workspace_id' => $other->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot->toIso8601String())->toBe('2026-05-18T09:00:00+00:00');
});

test('wraps across the week boundary to the next matching weekday', function () {
    // Saturday 2026-05-23 18:00; only slot is Sunday(0) 08:00 → next day.
    CarbonImmutable::setTestNow('2026-05-23T18:00:00+00:00');
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'UTC', [[0, 8]]); // Sunday 08:00

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    // 2026-05-24 is the next Sunday.
    expect($slot->toIso8601String())->toBe('2026-05-24T08:00:00+00:00');
});

test('interprets slots as wall-clock in the schedule timezone (DST spring-forward)', function () {
    // 2026-03-08: America/New_York springs forward (EST→EDT) at 02:00 local.
    // Sunday(0) 09:00 ET that day = 13:00 UTC (EDT = UTC-4).
    CarbonImmutable::setTestNow('2026-03-08T11:00:00+00:00'); // ~06:00 ET, before 09:00 ET
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'America/New_York', [[0, 9]]); // Sunday 09:00 local

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot->toIso8601String())->toBe('2026-03-08T13:00:00+00:00');
});

test('returns null when no slot is free within the 14-day horizon', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    // Single weekly slot Mon 09:00; occupy the two Mondays inside the 14-day horizon.
    scheduleWith($workspace, 'UTC', [[1, 9]]);

    foreach (['2026-05-18T09:00:00+00:00', '2026-05-25T09:00:00+00:00'] as $taken) {
        Post::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $taken,
        ]);
    }

    expect(app(NextSlotResolver::class)->resolve($workspace))->toBeNull();
});
