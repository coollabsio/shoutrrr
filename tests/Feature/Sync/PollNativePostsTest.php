<?php

declare(strict_types=1);

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Jobs\IngestNativePosts;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['sync.enabled' => true]);
});

test('dispatches an ingest job per tracked active account', function () {
    [, $workspace] = ownerActingIn();
    $tracked = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active->value]);
    ConnectedAccountNativeWatch::create(['connected_account_id' => $tracked->id, 'workspace_id' => $workspace->id, 'enabled_at' => now()]);
    // An untracked account is ignored.
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);

    $this->artisan('sync:poll-native')->assertSuccessful();

    Queue::assertPushed(IngestNativePosts::class, 1);
});

test('is inert when sync.enabled is false', function () {
    config(['sync.enabled' => false]);
    [, $workspace] = ownerActingIn();
    $tracked = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);
    ConnectedAccountNativeWatch::create(['connected_account_id' => $tracked->id, 'workspace_id' => $workspace->id, 'enabled_at' => now()]);

    $this->artisan('sync:poll-native')->assertSuccessful();

    Queue::assertNothingPushed();
});
