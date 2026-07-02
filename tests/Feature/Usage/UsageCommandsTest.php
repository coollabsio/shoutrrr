<?php

use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use App\Models\Workspace;
use Illuminate\Support\Facades\Date;

it('reconcile heals a drifted counter from raw succeeded events', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();

    UsageEvent::factory()->count(2)->create([
        'workspace_id' => $workspace->id, 'category' => 'publish', 'platform' => 'x',
        'operation' => 'post', 'quota_weight' => 1, 'succeeded' => true, 'occurred_at' => Date::now(),
    ]);
    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id, 'category' => 'publish', 'platform' => 'x',
        'operation' => 'post', 'quota_weight' => 5, 'succeeded' => false, 'occurred_at' => Date::now(),
    ]);
    // Deliberately-wrong counter:
    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id, 'platform' => 'x', 'operation' => 'post', 'event_count' => 99, 'total_quota' => 99,
    ]);

    $this->artisan('usage:reconcile')->assertSuccessful();

    expect(UsagePeriodCounter::count())->toBe(1); // no duplicate spawned

    $counter = UsagePeriodCounter::firstOrFail();
    expect($counter->event_count)->toBe(2)->and($counter->total_quota)->toBe(2); // failed event excluded
});

it('prune deletes events older than the retention window', function () {
    config()->set('usage.retention_days', 30);
    $workspace = Workspace::factory()->create();

    UsageEvent::factory()->create(['workspace_id' => $workspace->id, 'occurred_at' => Date::now()->subDays(60)]);
    UsageEvent::factory()->create(['workspace_id' => $workspace->id, 'occurred_at' => Date::now()->subDays(5)]);

    $this->artisan('usage:prune')->assertSuccessful();

    expect(UsageEvent::count())->toBe(1);
});

it('prune keeps open-period events even under a short retention window', function () {
    config()->set('usage.retention_days', 1);
    $workspace = Workspace::factory()->create();

    // Open period: must survive despite being older than the 1-day window, because
    // reconcile still recomputes this period's counters from raw events.
    $current = UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'occurred_at' => Date::now()->startOfMonth(),
    ]);
    // Closed prior period: safely prunable.
    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'occurred_at' => Date::now()->startOfMonth()->subDay(),
    ]);

    $this->artisan('usage:prune')->assertSuccessful();

    expect(UsageEvent::count())->toBe(1)
        ->and(UsageEvent::firstOrFail()->is($current))->toBeTrue();
});
