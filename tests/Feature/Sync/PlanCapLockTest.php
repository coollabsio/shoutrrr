<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\NativeTracking;
use App\Services\Sync\SyncPipelineService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function subscribedCapWorkspace(): Workspace
{
    config(['subscriptions.enabled' => true, 'subscriptions.max_sync_pipelines' => 1, 'subscriptions.max_native_tracked' => 1]);
    $workspace = Workspace::factory()->create(['is_initial' => false]);
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    return $workspace;
}

/**
 * Records each query with the transaction depth it ran at, relative to the
 * depth when recording started.
 *
 * @return ArrayObject<int, array{sql: string, depth: int}>
 */
function recordQueryDepths(): ArrayObject
{
    $baseline = DB::transactionLevel();
    $log = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($log, $baseline): void {
        $log[] = ['sql' => $query->sql, 'depth' => DB::transactionLevel() - $baseline];
    });

    return $log;
}

/**
 * Asserts the workspace row is re-read (FOR UPDATE on pgsql/mysql; sqlite
 * drops the clause) inside a transaction before the cap count runs in it.
 *
 * @param  ArrayObject<int, array{sql: string, depth: int}>  $log
 */
function assertCapCountedUnderWorkspaceLock(ArrayObject $log, string $countedTable): void
{
    $entries = array_values($log->getArrayCopy());
    $lockAt = collect($entries)->search(fn (array $q): bool => $q['depth'] > 0 && str_starts_with($q['sql'], 'select * from "workspaces" where "workspaces"."id" = ?'));
    $countAt = collect($entries)->search(fn (array $q): bool => $q['depth'] > 0 && str_contains($q['sql'], 'count(*)') && str_contains($q['sql'], '"'.$countedTable.'"'));

    expect($lockAt)->not->toBeFalse()
        ->and($countAt)->not->toBeFalse()
        ->and($lockAt)->toBeLessThan($countAt);
}

test('sync pipeline creation counts the plan cap under a workspace lock and refuses at the cap', function (): void {
    $workspace = subscribedCapWorkspace();
    $user = User::factory()->create();
    $source = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Discord]);
    $destination = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Discord]);
    $input = ['name' => 'Sync', 'source_connected_account_id' => $source->id, 'destination_connected_account_ids' => [$destination->id], 'track_source' => false];

    $log = recordQueryDepths();
    app(SyncPipelineService::class)->create($workspace, $user, $input);
    assertCapCountedUnderWorkspaceLock($log, 'sync_pipelines');

    expect(fn () => app(SyncPipelineService::class)->create($workspace, $user, $input))
        ->toThrow(ValidationException::class, "You've reached your plan's limit of 1 sync pipelines.");
    expect(SyncPipeline::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('native tracking counts the plan cap under a workspace lock and refuses at the cap', function (): void {
    $workspace = subscribedCapWorkspace();
    $user = User::factory()->create();
    $first = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Bluesky]);
    $second = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Bluesky]);

    $log = recordQueryDepths();
    app(NativeTracking::class)->track($workspace, $user, $first);
    assertCapCountedUnderWorkspaceLock($log, 'connected_account_native_watches');

    expect(fn () => app(NativeTracking::class)->track($workspace, $user, $second))
        ->toThrow(ValidationException::class, "You've reached your plan's limit of 1 tracked accounts.");
    expect(app(NativeTracking::class)->trackIfEligible($workspace, $user, $second))->toBeFalse()
        ->and(ConnectedAccountNativeWatch::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});
