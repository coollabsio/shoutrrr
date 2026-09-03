<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConnectedAccount;

test('an owner can enable and disable native tracking', function () {
    [, $workspace] = ownerActingIn();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);

    $this->post("/settings/workspace/native-tracking/{$account->id}")->assertRedirect();
    $this->assertDatabaseHas('connected_account_native_watches', ['connected_account_id' => $account->id]);

    $this->delete("/settings/workspace/native-tracking/{$account->id}")->assertRedirect();
    $this->assertDatabaseMissing('connected_account_native_watches', ['connected_account_id' => $account->id]);
});

test('tracking an unsupported platform is rejected', function () {
    [, $workspace] = ownerActingIn();
    $account = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $this->post("/settings/workspace/native-tracking/{$account->id}")->assertSessionHasErrors();
    $this->assertDatabaseMissing('connected_account_native_watches', ['connected_account_id' => $account->id]);
});

test('enabling is blocked at the cap when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true, 'subscriptions.max_native_tracked' => 1]);
    [, $workspace] = ownerActingIn();
    $workspace->forceFill(['is_initial' => false])->save();
    $workspace->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.fake()->uuid(), 'stripe_status' => 'active', 'stripe_price' => 'price_test', 'quantity' => 1]);
    $a1 = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);
    $a2 = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);
    $this->post("/settings/workspace/native-tracking/{$a1->id}")->assertRedirect();

    $this->post("/settings/workspace/native-tracking/{$a2->id}")->assertSessionHasErrors();
});
