<?php

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use App\Models\Workspace;
use App\Services\Usage\UsageRecorder;
use App\Support\UsageOperation;

function recordUsage(array $overrides = []): void
{
    $args = array_merge([
        'category' => UsageCategory::Publish,
        'operation' => UsageOperation::POST,
        'workspaceId' => $overrides['workspaceId'],
        'platform' => Platform::X,
    ], $overrides);

    app(UsageRecorder::class)->record(
        category: $args['category'],
        operation: $args['operation'],
        workspaceId: $args['workspaceId'],
        platform: $args['platform'] ?? null,
        quotaWeight: $args['quotaWeight'] ?? 1,
        succeeded: $args['succeeded'] ?? true,
        meta: $args['meta'] ?? [],
    );
}

it('no-ops when tracking is disabled', function () {
    $workspace = Workspace::factory()->create();

    recordUsage(['workspaceId' => $workspace->id]);

    expect(UsageEvent::count())->toBe(0)->and(UsagePeriodCounter::count())->toBe(0);
});

it('records an event and increments the counter on success', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();

    recordUsage(['workspaceId' => $workspace->id, 'quotaWeight' => 2]);

    expect(UsageEvent::count())->toBe(1);
    $counter = UsagePeriodCounter::firstOrFail();
    expect($counter->total_quota)->toBe(2)
        ->and($counter->event_count)->toBe(1)
        ->and($counter->platform)->toBe('x');
});

it('records a failure without touching the counter', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();

    recordUsage(['workspaceId' => $workspace->id, 'succeeded' => false]);

    expect(UsageEvent::where('succeeded', false)->count())->toBe(1)
        ->and(UsagePeriodCounter::count())->toBe(0);
});

it('accumulates repeated successes into one counter row', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();

    foreach (range(1, 3) as $ignored) {
        recordUsage(['workspaceId' => $workspace->id]);
    }

    expect(UsagePeriodCounter::count())->toBe(1);
    $counter = UsagePeriodCounter::firstOrFail();
    expect($counter->event_count)->toBe(3)->and($counter->total_quota)->toBe(3);
});

it('stores the none sentinel for platform-less operations', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();

    recordUsage(['workspaceId' => $workspace->id, 'category' => UsageCategory::ApiRequest, 'operation' => UsageOperation::MCP_REQUEST, 'platform' => null]);
    recordUsage(['workspaceId' => $workspace->id, 'category' => UsageCategory::ApiRequest, 'operation' => UsageOperation::MCP_REQUEST, 'platform' => null]);

    expect(UsagePeriodCounter::count())->toBe(1)
        ->and(UsagePeriodCounter::firstOrFail()->platform)->toBe('none');
});
