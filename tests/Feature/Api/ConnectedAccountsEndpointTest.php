<?php

use App\Models\ConnectedAccount;

test('lists connected accounts for the bound workspace only', function () {
    [, $workspace, $token] = issuedKey();
    $mine = ConnectedAccount::factory()->for($workspace)->create();
    $other = ConnectedAccount::factory()->create(); // different workspace

    $response = $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($mine->id)->not->toContain($other->id);
});
