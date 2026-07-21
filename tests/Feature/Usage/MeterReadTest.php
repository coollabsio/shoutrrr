<?php

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

function meterReadHarness(): object
{
    return new class
    {
        use TracksUsage;

        public function run(UsageCategory $c, string $op, ConnectedAccount $a, Response $r, array $ids): void
        {
            $this->meterRead($c, $op, $a, $r, $ids);
        }
    };
}

function jsonResponse(int $status, array $body): Response
{
    return new Response(new GuzzleHttp\Psr7\Response($status, [], json_encode($body)));
}

beforeEach(function (): void {
    Cache::flush();
    app(InstanceSettings::class)->update(['usage_tracking_enabled' => true]);
});

it('meters deduped read count on success', function (): void {
    $account = ConnectedAccount::factory()->for(Workspace::factory())->create(['platform' => Platform::X]);

    meterReadHarness()->run(UsageCategory::ExternalApi, UsageOperation::REPLIES_FETCH, $account, jsonResponse(200, []), ['a', 'b']);
    meterReadHarness()->run(UsageCategory::ExternalApi, UsageOperation::REPLIES_FETCH, $account, jsonResponse(200, []), ['b', 'c']);

    $weights = UsageEvent::query()->where('operation', UsageOperation::REPLIES_FETCH)->orderBy('occurred_at')->pluck('quota_weight');

    expect($weights->all())->toBe([2, 1]);
});

it('does not increment the counter on a failed read', function (): void {
    $account = ConnectedAccount::factory()->for(Workspace::factory())->create(['platform' => Platform::X]);

    meterReadHarness()->run(UsageCategory::ExternalApi, UsageOperation::REPLIES_FETCH, $account, jsonResponse(429, []), ['a', 'b']);

    expect(UsageEvent::query()->where('succeeded', true)->count())->toBe(0);
});
