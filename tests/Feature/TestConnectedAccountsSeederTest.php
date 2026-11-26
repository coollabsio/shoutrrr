<?php

declare(strict_types=1);

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\TestConnectedAccountsSeeder;

test('the test accounts seeder adds an active tiktok account with dummy tokens', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['slug' => 'test-workspace', 'owner_id' => $owner->id]);

    $this->seed(TestConnectedAccountsSeeder::class);

    $account = ConnectedAccount::withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->where('platform', Platform::TikTok->value)
        ->sole();

    expect($account->handle)->toBe('@test_tiktok')
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->secret->access_token)->toBe('tiktok-test-token')
        ->and($account->token_expires_at->isFuture())->toBeTrue();
});
