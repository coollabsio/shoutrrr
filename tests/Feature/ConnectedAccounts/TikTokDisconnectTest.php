<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('services.tiktok.client_id', 'tiktok-client-key');
    config()->set('services.tiktok.client_secret', 'tiktok-client-secret');
});

function connectedTikTokAccount(Workspace $workspace, string $openId = 'open-1'): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::TikTok->value,
        'remote_account_id' => $openId,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tiktok-access',
        'refresh_token' => 'tiktok-refresh',
    ]);

    return $account;
}

test('disconnecting a tiktok account revokes the grant on TikTok', function () {
    [, $workspace] = ownerActingIn();
    $account = connectedTikTokAccount($workspace);
    Http::fake(['https://open.tiktokapis.com/v2/oauth/revoke/' => Http::response([])]);

    test()->delete("/accounts/{$account->id}")
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('success', 'Account disconnected.');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/revoke/'
        && $request->isForm()
        && $request['token'] === 'tiktok-access'
        && $request['client_key'] === 'tiktok-client-key'
        && $request['client_secret'] === 'tiktok-client-secret');
    expect(ConnectedAccount::withoutGlobalScopes()->find($account->id))->toBeNull();
});

test('disconnecting still removes the account when TikTok fails to revoke', function () {
    [, $workspace] = ownerActingIn();
    $account = connectedTikTokAccount($workspace);
    Http::fake(['https://open.tiktokapis.com/v2/oauth/revoke/' => Http::response(['error' => 'invalid_request'], 400)]);
    Log::spy();

    test()->delete("/accounts/{$account->id}")->assertSessionHas('success');

    expect(ConnectedAccount::withoutGlobalScopes()->find($account->id))->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'TikTok grant revocation failed on disconnect.'
            && ! str_contains(json_encode($context), 'tiktok-access'))
        ->once();
});

test('disconnecting keeps the grant while another workspace still uses the tiktok account', function () {
    [, $workspace] = ownerActingIn();
    $account = connectedTikTokAccount($workspace, 'shared-open-id');
    connectedTikTokAccount(Workspace::factory()->create(), 'shared-open-id');
    Http::fake();

    test()->delete("/accounts/{$account->id}")->assertSessionHas('success');

    Http::assertNothingSent();
    expect(ConnectedAccount::withoutGlobalScopes()->where('remote_account_id', 'shared-open-id')->count())->toBe(1);
});

test('disconnecting with an expired tiktok token exchanges it once and revokes the new one', function () {
    [, $workspace] = ownerActingIn();
    $account = connectedTikTokAccount($workspace);
    $account->forceFill(['token_expires_at' => now()->subMinute()])->save();
    Http::fake([
        'https://open.tiktokapis.com/v2/oauth/token/' => Http::response([
            'access_token' => 'exchanged-access',
            'refresh_token' => 'exchanged-refresh',
            'expires_in' => 7200,
            'refresh_expires_in' => 15552000,
        ]),
        'https://open.tiktokapis.com/v2/oauth/revoke/' => Http::response([]),
    ]);

    test()->delete("/accounts/{$account->id}")->assertSessionHas('success');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/revoke/'
        && $request['token'] === 'exchanged-access');
    expect(ConnectedAccount::withoutGlobalScopes()->find($account->id))->toBeNull();
});

test('disconnecting with a dead tiktok refresh token neither flags the account nor notifies anyone', function () {
    [, $workspace] = ownerActingIn();
    $account = connectedTikTokAccount($workspace);
    $account->forceFill(['token_expires_at' => now()->subMinute()])->save();
    Http::fake([
        'https://open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid'], 400),
        'https://open.tiktokapis.com/v2/oauth/revoke/' => Http::response([]),
    ]);
    Notification::fake();
    $flagged = false;
    ConnectedAccount::updated(function (ConnectedAccount $updated) use (&$flagged): void {
        $flagged = $flagged || $updated->status === ConnectedAccountStatus::NeedsAttention;
    });

    test()->delete("/accounts/{$account->id}")
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('success', 'Account disconnected.');

    expect($flagged)->toBeFalse()
        ->and(ConnectedAccount::withoutGlobalScopes()->find($account->id))->toBeNull();
    Notification::assertNothingSent();
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/revoke/');
});

test('disconnecting other platforms does not call TikTok', function () {
    [, $workspace] = ownerActingIn();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id]);
    Http::fake();

    test()->delete("/accounts/{$account->id}")->assertSessionHas('success');

    Http::assertNothingSent();
});
