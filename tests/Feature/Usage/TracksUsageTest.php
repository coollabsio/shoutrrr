<?php

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\UsageEvent;
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
