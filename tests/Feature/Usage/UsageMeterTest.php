<?php

use App\Enums\Platform;
use App\Models\UsagePeriodCounter;
use App\Models\Workspace;
use App\Services\Usage\UsageMeter;
use App\Support\UsageOperation;

it('sums quota for the current period, filtered by platform + operation', function () {
    $workspace = Workspace::factory()->create();
    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id, 'platform' => 'x', 'operation' => UsageOperation::POST, 'total_quota' => 7, 'event_count' => 4,
    ]);

    $meter = app(UsageMeter::class);

    expect($meter->currentPeriodQuota($workspace->id, Platform::X, UsageOperation::POST))->toBe(7)
        ->and($meter->currentPeriodCount($workspace->id, Platform::X, UsageOperation::POST))->toBe(4)
        ->and($meter->remaining($workspace->id, 10, Platform::X, UsageOperation::POST))->toBe(3);
});

it('returns zero / full remaining for an unknown workspace', function () {
    $meter = app(UsageMeter::class);

    expect($meter->currentPeriodQuota('missing-id'))->toBe(0)
        ->and($meter->remaining('missing-id', 5))->toBe(5);
});

it('reads the none sentinel when platform is null', function () {
    $workspace = Workspace::factory()->create();
    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id, 'platform' => 'none', 'operation' => UsageOperation::MCP_REQUEST, 'total_quota' => 5,
    ]);

    expect(app(UsageMeter::class)->currentPeriodQuota($workspace->id, null, UsageOperation::MCP_REQUEST))->toBe(5);
});
