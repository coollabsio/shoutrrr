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

test('returns null when every slot within the max horizon is occupied', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    // Single weekly slot Mon 09:00; occupy every Monday inside the 90-day horizon.
    scheduleWith($workspace, 'UTC', [[1, 9]]);

    $monday = CarbonImmutable::parse('2026-05-18T09:00:00+00:00');
    for ($week = 0; $week < 13; $week++) {
        Post::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $monday->addWeeks($week)->toIso8601String(),
        ]);
    }

    expect(app(NextSlotResolver::class)->resolve($workspace))->toBeNull();
});

test('surfaces a full runway of open slots for a sparse schedule (issue #143)', function () {
    // Regression: two slots/week must still yield a rolling runway that reaches
    // well past a two-week window, not the ~4 slots a 14-day horizon would give.
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00'); // Monday 08:30 UTC
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'UTC', [[1, 9], [4, 15]]); // Mon 09:00, Thu 15:00

    $slots = app(NextSlotResolver::class)->availableSlots($workspace);

    expect($slots)->toHaveCount(NextSlotResolver::TARGET_SLOTS);
    expect($slots[0]->toIso8601String())->toBe('2026-05-18T09:00:00+00:00');
    // The runway must extend beyond the old fixed 14-day window.
    expect($slots[NextSlotResolver::TARGET_SLOTS - 1]->greaterThan(
        CarbonImmutable::now()->addDays(14),
    ))->toBeTrue();
});

test('caps a dense schedule at the target slot count, ascending', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    // A slot every weekday at 09:00.
    scheduleWith($workspace, 'UTC', [[0, 9], [1, 9], [2, 9], [3, 9], [4, 9], [5, 9], [6, 9]]);

    $slots = app(NextSlotResolver::class)->availableSlots($workspace);

    expect($slots)->toHaveCount(NextSlotResolver::TARGET_SLOTS);
    expect($slots[0]->toIso8601String())->toBe('2026-05-18T09:00:00+00:00');
    // Ten consecutive daily slots ending ten days out, strictly ascending.
    expect($slots[NextSlotResolver::TARGET_SLOTS - 1]->toIso8601String())->toBe('2026-05-27T09:00:00+00:00');
    $sorted = $slots;
    usort($sorted, fn ($a, $b) => $a <=> $b);
    expect($slots)->toEqual($sorted);
});

test('backfills the runway from further out when early slots are occupied', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    scheduleWith($workspace, 'UTC', [[0, 9], [1, 9], [2, 9], [3, 9], [4, 9], [5, 9], [6, 9]]);

    // Occupy the very next slot; the runway must stay full and start one day later.
    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    $slots = app(NextSlotResolver::class)->availableSlots($workspace);

    expect($slots)->toHaveCount(NextSlotResolver::TARGET_SLOTS);
    expect($slots[0]->toIso8601String())->toBe('2026-05-19T09:00:00+00:00');
    expect($slots[NextSlotResolver::TARGET_SLOTS - 1]->toIso8601String())->toBe('2026-05-28T09:00:00+00:00');
});

test('resolves a slot with a non-zero minute', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00'); // Monday 08:30 UTC
    $workspace = Workspace::factory()->create();
    $schedule = PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id, 'weekday' => 1, 'hour' => 9, 'minute' => 30, 'position' => 0,
    ]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot?->toIso8601String())->toBe('2026-05-18T09:30:00+00:00');
});

test('orders same-hour slots by minute', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    $schedule = PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    foreach ([[1, 9, 45], [1, 9, 15]] as [$w, $h, $m]) {
        PostingScheduleSlot::factory()->create([
            'posting_schedule_id' => $schedule->id, 'weekday' => $w, 'hour' => $h, 'minute' => $m, 'position' => 0,
        ]);
    }

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot?->toIso8601String())->toBe('2026-05-18T09:15:00+00:00');
});

test('skips an occupied minute-precise slot and uses the next', function () {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    $workspace = Workspace::factory()->create();
    $schedule = PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    foreach ([[1, 9, 15], [1, 9, 45]] as [$w, $h, $m]) {
        PostingScheduleSlot::factory()->create([
            'posting_schedule_id' => $schedule->id, 'weekday' => $w, 'hour' => $h, 'minute' => $m, 'position' => 0,
        ]);
    }
    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-05-18T09:15:00+00:00',
    ]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot?->toIso8601String())->toBe('2026-05-18T09:45:00+00:00');
});

test('a non-zero minute slot is converted from a non-UTC schedule timezone', function () {
    // 09:30 Europe/Berlin (CEST, UTC+2 in summer) === 07:30 UTC. 2026-05-18 is a Monday.
    CarbonImmutable::setTestNow('2026-05-18T05:00:00+00:00');
    $workspace = Workspace::factory()->create();
    $schedule = PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'Europe/Berlin']);
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id, 'weekday' => 1, 'hour' => 9, 'minute' => 30, 'position' => 0,
    ]);

    $slot = app(NextSlotResolver::class)->resolve($workspace);

    expect($slot?->toIso8601String())->toBe('2026-05-18T07:30:00+00:00');
});
