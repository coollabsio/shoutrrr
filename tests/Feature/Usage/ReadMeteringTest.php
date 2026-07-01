<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Metrics\Connectors\XMetricsConnector;
use Illuminate\Support\Facades\Http;

it('records a metrics read when X account metrics are fetched', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::X->value,
        'remote_account_id' => '555',
    ]);

    Http::fake([
        'api.twitter.com/2/users/*' => Http::response(['data' => ['public_metrics' => ['followers_count' => 1]]], 200),
    ]);

    app(XMetricsConnector::class)->fetchAccount($account, ['access_token' => 'tok']);

    $event = UsageEvent::where('operation', 'metrics_fetch_account')->firstOrFail();
    expect($event->category)->toBe('external_api')
        ->and($event->platform)->toBe('x')
        ->and($event->workspace_id)->toBe($workspace->id);
});
