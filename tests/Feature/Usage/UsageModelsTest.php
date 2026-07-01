<?php

use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use Illuminate\Database\QueryException;

it('persists a usage event with casts', function () {
    $event = UsageEvent::factory()->create(['meta' => ['status' => 200]]);

    expect($event->fresh()->meta)->toBe(['status' => 200])
        ->and($event->quota_weight)->toBeInt()
        ->and($event->succeeded)->toBeTrue();
});

it('enforces one counter row per workspace/period/dimension', function () {
    $counter = UsagePeriodCounter::factory()->create();

    expect(fn () => UsagePeriodCounter::factory()->create([
        'workspace_id' => $counter->workspace_id,
        'period_start' => $counter->period_start,
        'category' => $counter->category,
        'platform' => $counter->platform,
        'operation' => $counter->operation,
    ]))->toThrow(QueryException::class);
});
