<?php

use App\Enums\ConnectedAccountStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\RefreshXTierTool;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * @return array<string, mixed>
 */
function xTierToolJson(TestResponse $response): array
{
    $payload = (fn (): array => $this->response->toArray())->call($response);

    return json_decode($payload['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

function freeTierXAccount(Workspace $workspace, array $attributes = []): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'handle' => '@acme',
        'token_expires_at' => now()->addHour(),
        'capabilities' => [
            'x_premium' => false,
            'max_text_length' => 280,
            'max_video_duration_seconds' => 140,
            'verified_type' => 'blue',
            'x_subscription_tier' => 'free',
        ],
        ...$attributes,
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'stored-access-token',
    ]);

    return $account;
}

test('refresh_x_tier stores the new tier, recovers the account, and returns the limits', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = freeTierXAccount($workspace, [
        'status' => ConnectedAccountStatus::NeedsAttention->value,
        'refresh_failed_at' => now(),
        'refresh_failure_reason' => 'HTTP 401: invalid token',
    ]);
    Http::fake([
        'https://api.x.com/2/users/me*' => Http::response([
            'data' => ['id' => $account->remote_account_id, 'subscription_type' => 'PremiumPlus', 'verified_type' => 'none'],
        ]),
    ]);

    $response = ShoutrrrServer::actingAs($user)->tool(RefreshXTierTool::class, ['account_id' => $account->id]);

    expect(xTierToolJson($response))->toBe([
        'account_id' => $account->id,
        'handle' => '@acme',
        'x_subscription_tier' => 'premium_plus',
        'x_subscription_label' => 'X Premium+',
        'max_text_length' => 25_000,
        'max_video_duration_seconds' => 14_400,
    ]);
    $fresh = $account->fresh();
    expect($fresh->xSubscriptionTier())->toBe('premium_plus')
        ->and($fresh->status)->toBe(ConnectedAccountStatus::Active)
        ->and($fresh->refresh_failed_at)->toBeNull();
});

test('a failed X lookup keeps the existing limits', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = freeTierXAccount($workspace);
    Http::fake(['https://api.x.com/2/users/me*' => Http::response([], 503)]);

    ShoutrrrServer::actingAs($user)->tool(RefreshXTierTool::class, ['account_id' => $account->id])
        ->assertHasErrors(["Could not refresh @acme's X account tier. The existing limits were kept."]);

    expect($account->fresh()->xSubscriptionTier())->toBe('free')
        ->and($account->fresh()->maxTextLength())->toBe(280);
});

test('refresh_x_tier rejects non-X accounts', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]);
    Http::fake();

    ShoutrrrServer::actingAs($user)->tool(RefreshXTierTool::class, ['account_id' => $account->id])
        ->assertHasErrors(['Only X accounts have a subscription tier.']);

    Http::assertNothingSent();
});

test('a plain member cannot refresh the X tier', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    bindTokenToWorkspace($user, $workspace);
    $account = freeTierXAccount($workspace);
    Http::fake();

    ShoutrrrServer::actingAs($user)->tool(RefreshXTierTool::class, ['account_id' => $account->id])
        ->assertHasErrors(['You do not have permission to perform this action in this workspace.']);

    Http::assertNothingSent();
    expect($account->fresh()->xSubscriptionTier())->toBe('free');
});

test('refresh_x_tier cannot reach an account in another workspace', function (): void {
    [$user] = mcpOwner();
    $foreign = freeTierXAccount(Workspace::factory()->create());
    Http::fake();

    ShoutrrrServer::actingAs($user)->tool(RefreshXTierTool::class, ['account_id' => $foreign->id])
        ->assertHasErrors(['No connected account with that id exists in this workspace.']);

    Http::assertNothingSent();
});
