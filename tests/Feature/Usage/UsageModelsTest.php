<?php

use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('persists a usage event with casts', function () {
    $event = UsageEvent::factory()->create(['meta' => ['status' => 200]]);

    expect($event->fresh()->meta)->toBe(['status' => 200])
        ->and($event->quota_weight)->toBeInt()
        ->and($event->cost_weight_microusd)->toBeInt()
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

it('indexes the count-since quota check query shape', function () {
    $indexes = collect(Schema::getIndexes('usage_events'));

    expect($indexes->contains(
        fn (array $index): bool => $index['columns'] === ['workspace_id', 'platform', 'operation', 'succeeded', 'occurred_at'],
    ))->toBeTrue();
});
