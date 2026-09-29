<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;

test('supportsNativeRead is true only for readable platforms', function () {
    expect(Platform::X->supportsNativeRead())->toBeTrue()
        ->and(Platform::Bluesky->supportsNativeRead())->toBeTrue()
        ->and(Platform::Threads->supportsNativeRead())->toBeTrue()
        ->and(Platform::Instagram->supportsNativeRead())->toBeTrue()
        ->and(Platform::Facebook->supportsNativeRead())->toBeTrue()
        ->and(Platform::LinkedIn->supportsNativeRead())->toBeFalse()
        ->and(Platform::Discord->supportsNativeRead())->toBeFalse();
});

test('an account has one native watch that cascades on delete', function () {
    [, $workspace] = ownerActingIn();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    ConnectedAccountNativeWatch::create([
        'connected_account_id' => $account->id,
        'workspace_id' => $workspace->id,
        'enabled_at' => now(),
    ]);

    expect($account->nativeWatch)->not->toBeNull();
    $account->delete();
    $this->assertDatabaseMissing('connected_account_native_watches', ['connected_account_id' => $account->id]);
});
