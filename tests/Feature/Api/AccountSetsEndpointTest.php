<?php

use App\Models\AccountSet;
use App\Models\ConnectedAccount;

use function issuedKey;

test('lists account sets', function () {
    [, $workspace, $token] = issuedKey();
    $set = AccountSet::factory()->for($workspace)->create();

    $this->withToken($token)->getJson('/api/v1/account-sets')
        ->assertOk()
        ->assertJsonPath('account_sets.0.id', $set->id);
});

test('creates an account set with scoped members', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->postJson('/api/v1/account-sets', [
        'name' => 'Launch',
        'connected_account_ids' => [$account->id],
    ])
        ->assertCreated()
        ->assertJsonPath('name', 'Launch')
        ->assertJsonPath('connected_account_ids.0', $account->id);
});

test('updates an account set', function () {
    [, $workspace, $token] = issuedKey();
    $set = AccountSet::factory()->for($workspace)->create(['name' => 'Old']);

    $this->withToken($token)->patchJson("/api/v1/account-sets/{$set->id}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('name', 'New');
});

test('deletes an account set', function () {
    [, $workspace, $token] = issuedKey();
    $set = AccountSet::factory()->for($workspace)->create();

    $this->withToken($token)->deleteJson("/api/v1/account-sets/{$set->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(AccountSet::whereKey($set->id)->exists())->toBeFalse();
});
