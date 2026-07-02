<?php

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use App\Models\Workspace;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function usageMeterer(): object
{
    return new class
    {
        use TracksUsage;

        public function run(ConnectedAccount $account, Response $response): void
        {
            $this->meter(UsageCategory::Publish, UsageOperation::POST, $account, $response);
        }
    };
}

it('records a succeeded event with rate-limit headers', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X->value]);

    Http::fake(['https://meter.test/*' => Http::response('ok', 200, ['x-rate-limit-remaining' => '42'])]);
    usageMeterer()->run($account, Http::get('https://meter.test/tweets'));

    $event = UsageEvent::firstOrFail();
    expect($event->operation)->toBe('post')
        ->and($event->platform)->toBe('x')
        ->and($event->workspace_id)->toBe($workspace->id)
        ->and($event->succeeded)->toBeTrue()
        ->and($event->meta['rate_remaining'])->toBe('42');
});

it('marks the event failed for an error response', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X->value]);

    Http::fake(['https://meter.test/*' => Http::response('nope', 429)]);
    usageMeterer()->run($account, Http::get('https://meter.test/tweets'));

    expect(UsageEvent::where('succeeded', false)->count())->toBe(1);
});

it('counts a tolerated non-2xx response as success when succeeded is overridden', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X->value]);

    Http::fake(['https://meter.test/*' => Http::response('gone', 404)]);

    $meterer = new class
    {
        use TracksUsage;

        public function run(ConnectedAccount $account, Response $response): void
        {
            $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $account, $response, succeeded: $response->status() === 404);
        }
    };
    $meterer->run($account, Http::get('https://meter.test/thing'));

    $event = UsageEvent::firstOrFail();
    expect($event->succeeded)->toBeTrue()
        ->and(UsagePeriodCounter::count())->toBe(1);
});

it('captures bluesky ratelimit-* headers that omit the x- prefix', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Bluesky->value]);

    Http::fake(['https://meter.test/*' => Http::response('ok', 200, ['ratelimit-remaining' => '17'])]);
    usageMeterer()->run($account, Http::get('https://meter.test/xrpc'));

    expect(UsageEvent::firstOrFail()->meta['rate_remaining'])->toBe('17');
});
