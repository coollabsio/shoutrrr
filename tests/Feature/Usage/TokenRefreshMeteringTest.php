<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Http;

function makeRefreshableXAccount(Workspace $workspace): ConnectedAccount
{
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'old',
        'refresh_token' => 'refresh-old',
    ]);

    return $account->fresh();
}

it('records a token_refresh event when a token is refreshed', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $account = makeRefreshableXAccount($workspace);

    Http::fake(['https://api.twitter.com/2/oauth2/token' => Http::response(['access_token' => 'new', 'expires_in' => 7200], 200)]);

    app(TokenManager::class)->fresh($account, force: true);

    expect(UsageEvent::where('operation', 'token_refresh')->where('workspace_id', $workspace->id)->count())->toBe(1);
});
