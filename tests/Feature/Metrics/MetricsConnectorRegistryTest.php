<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\Metrics\Connectors\BlueskyMetricsConnector;
use App\Services\Metrics\Connectors\LinkedInMetricsConnector;
use App\Services\Metrics\Connectors\XMetricsConnector;
use App\Services\Metrics\MetricsConnectorRegistry;

test('registry resolves a connector per platform', function () {
    $registry = app(MetricsConnectorRegistry::class);

    expect($registry->for(Platform::X))->toBeInstanceOf(XMetricsConnector::class);
    expect($registry->for(Platform::Bluesky))->toBeInstanceOf(BlueskyMetricsConnector::class);
    expect($registry->for(Platform::LinkedIn))->toBeInstanceOf(LinkedInMetricsConnector::class);
});

test('linkedin reports unsupported for account fetch', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn]);
    $r = app(LinkedInMetricsConnector::class)->fetchAccount($account, []);
    expect($r->status)->toBe(MetricsStatus::Unsupported);
});
