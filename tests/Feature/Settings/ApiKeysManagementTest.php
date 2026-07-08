<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;

beforeEach(function () {
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys', ['--no-interaction' => true]);
    }

    Client::factory()->asPersonalAccessTokenClient()->create(['provider' => 'users']);
});

/**
 * @return array{0: User, 1: Workspace}
 */
function ownerInWorkspaceForApiKeys(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => 'owner']);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$user, $workspace];
}

test('an owner can create an api key and sees the plaintext once', function () {
    [$user, $workspace] = ownerInWorkspaceForApiKeys();

    $response = $this->actingAs($user)->post('/settings/api-keys', [
        'name' => 'CI bot',
        'scope' => 'write',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('api_keys', ['workspace_id' => $workspace->id, 'name' => 'CI bot', 'scope' => 'write']);
    expect(session('flash.plainTextApiKey'))->toBeString()->not->toBeEmpty();
});

test('a member without settings.manage cannot create a key', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => 'member']);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)->post('/settings/api-keys', ['name' => 'x', 'scope' => 'read'])
        ->assertForbidden();
});

test('an owner can revoke a key', function () {
    [$user, $workspace] = ownerInWorkspaceForApiKeys();
    $apiKey = ApiKey::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    $this->actingAs($user)->delete("/settings/api-keys/{$apiKey->id}")->assertRedirect();

    expect($apiKey->fresh()->revoked_at)->not->toBeNull();
});

test('keys from another workspace are not manageable', function () {
    [$user] = ownerInWorkspaceForApiKeys();
    $foreign = ApiKey::factory()->create(); // other workspace

    $this->actingAs($user)->delete("/settings/api-keys/{$foreign->id}")->assertNotFound();
});
