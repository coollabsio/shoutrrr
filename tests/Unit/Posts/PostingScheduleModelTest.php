<?php

use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

test('a posting schedule has ordered slots and belongs to a workspace', function () {
    $workspace = Workspace::factory()->create();
    $schedule = PostingSchedule::factory()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'America/New_York',
    ]);

    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => 3,
        'hour' => 9,
        'position' => 1,
    ]);
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => 1,
        'hour' => 17,
        'position' => 0,
    ]);

    $schedule->refresh();

    expect($schedule->workspace->id)->toBe($workspace->id);
    expect($schedule->timezone)->toBe('America/New_York');

    $slots = $schedule->slots;
    expect($slots)->toHaveCount(2);
    // ordered by weekday, hour → (1,17) before (3,9)
    expect($slots->first()->weekday)->toBe(1);
    expect($slots->first()->hour)->toBe(17);
    expect($slots->first()->weekday)->toBeInt();
    expect($slots->last()->weekday)->toBe(3);
});

test('the slot unique constraint blocks duplicate weekday+hour per schedule', function () {
    $schedule = PostingSchedule::factory()->create();
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => 2,
        'hour' => 8,
    ]);

    expect(fn () => PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => 2,
        'hour' => 8,
    ]))->toThrow(QueryException::class);
});
