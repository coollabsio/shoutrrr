<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;

test('disabling an account clears it as the workspace default', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $workspace->forceFill(['default_connected_account_id' => $account->id])->save();

    $this->withToken($token)->patchJson("/api/v1/connected-accounts/{$account->id}", ['enabled' => false])
        ->assertOk()
        ->assertExactJson(['id' => $account->id, 'enabled' => false]);

    expect($account->fresh()->isDisabled())->toBeTrue()
        ->and($workspace->fresh()->default_connected_account_id)->toBeNull();

    $this->withToken($token)->patchJson("/api/v1/connected-accounts/{$account->id}", ['enabled' => true])
        ->assertOk()
        ->assertExactJson(['id' => $account->id, 'enabled' => true]);

    expect($account->fresh()->isDisabled())->toBeFalse();
});

test('enabled is required', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->patchJson("/api/v1/connected-accounts/{$account->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('enabled');
});

test('makes an account the workspace default', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->postJson("/api/v1/connected-accounts/{$account->id}/default")
        ->assertOk()
        ->assertExactJson(['id' => $account->id, 'default' => true]);

    expect($workspace->fresh()->default_connected_account_id)->toBe($account->id);
});

test('auto-repost merges into capabilities and keeps an omitted min_percentile', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::X->value,
        'capabilities' => ['x_tier' => 'premium'],
    ]);

    $this->withToken($token)->patchJson("/api/v1/connected-accounts/{$account->id}/auto-repost", [
        'enabled' => true,
        'min_percentile' => 0.8,
    ])->assertOk()->assertExactJson([
        'id' => $account->id,
        'auto_repost_enabled' => true,
        'min_percentile' => 0.8,
        'supports_repost' => true,
    ]);

    $this->withToken($token)->patchJson("/api/v1/connected-accounts/{$account->id}/auto-repost", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('auto_repost_enabled', false)
        ->assertJsonPath('min_percentile', 0.8);

    expect($account->fresh()->capabilities)->toBe([
        'x_tier' => 'premium',
        'auto_repost' => ['enabled' => false, 'min_percentile' => 0.8],
    ]);
});

test('auto-repost stays off on a platform without reposts', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->discord()->create();

    $this->withToken($token)->patchJson("/api/v1/connected-accounts/{$account->id}/auto-repost", ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('auto_repost_enabled', false)
        ->assertJsonPath('supports_repost', false);
});

test('auto-repost rejects a percentile above 1', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->patchJson("/api/v1/connected-accounts/{$account->id}/auto-repost", [
        'enabled' => true,
        'min_percentile' => 50,
    ])->assertUnprocessable()->assertJsonValidationErrors('min_percentile');
});

test('disconnect deletes the account, its secret, and the default pointer', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create();
    ConnectedAccountSecret::factory()->for($account, 'account')->create();
    $workspace->forceFill(['default_connected_account_id' => $account->id])->save();

    $this->withToken($token)->deleteJson("/api/v1/connected-accounts/{$account->id}")
        ->assertOk()
        ->assertExactJson(['deleted' => true, 'id' => $account->id]);

    expect(ConnectedAccount::withoutGlobalScopes()->find($account->id))->toBeNull()
        ->and(ConnectedAccountSecret::query()->where('connected_account_id', $account->id)->exists())->toBeFalse()
        ->and($workspace->fresh()->default_connected_account_id)->toBeNull();
});

test('account writes are 404 for another workspace account', function (string $method, string $suffix, array $body) {
    [, , $token] = issuedKey();
    $foreign = ConnectedAccount::factory()->create();

    $this->withToken($token)->json($method, "/api/v1/connected-accounts/{$foreign->id}{$suffix}", $body)->assertNotFound();

    expect(ConnectedAccount::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
})->with([
    'toggle' => ['PATCH', '', ['enabled' => false]],
    'default' => ['POST', '/default', []],
    'auto-repost' => ['PATCH', '/auto-repost', ['enabled' => true]],
    'disconnect' => ['DELETE', '', []],
]);

test('account writes are 403 for a member', function (string $method, string $suffix, array $body) {
    [$user, $workspace, $token] = issuedKey();
    $workspace->members()->where('user_id', $user->id)->update(['role' => 'member']);
    $account = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->json($method, "/api/v1/connected-accounts/{$account->id}{$suffix}", $body)->assertForbidden();

    expect(ConnectedAccount::query()->withoutGlobalScopes()->find($account->id))->not->toBeNull();
})->with([
    'toggle' => ['PATCH', '', ['enabled' => false]],
    'default' => ['POST', '/default', []],
    'auto-repost' => ['PATCH', '/auto-repost', ['enabled' => true]],
    'disconnect' => ['DELETE', '', []],
]);

test('account writes are 403 for a read-only key', function (string $method, string $suffix, array $body) {
    [, $workspace, $token] = issuedKey('read');
    $account = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->json($method, "/api/v1/connected-accounts/{$account->id}{$suffix}", $body)->assertForbidden();
})->with([
    'toggle' => ['PATCH', '', ['enabled' => false]],
    'default' => ['POST', '/default', []],
    'auto-repost' => ['PATCH', '/auto-repost', ['enabled' => true]],
    'disconnect' => ['DELETE', '', []],
    'track' => ['POST', '/native-tracking', []],
    'untrack' => ['DELETE', '/native-tracking', []],
]);
