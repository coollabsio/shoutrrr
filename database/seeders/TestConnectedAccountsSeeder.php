<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Seeds one fake, Active connected account per platform into the test workspace
 * so the accounts page, destination selector, and composer flows — including the
 * Instagram JPEG-variant path — can be exercised locally without real OAuth.
 *
 * The tokens are dummies: anything that actually calls a platform API (a real
 * publish/metrics/engagement request) will fail, by design. Connect, compose,
 * destination selection, and the browser-side Instagram JPEG conversion all work.
 *
 * Run with: php artisan db:seed --class=TestConnectedAccountsSeeder
 */
class TestConnectedAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::query()->where('slug', 'test-workspace')->first()
            ?? Workspace::query()->oldest()->first();

        if ($workspace === null) {
            $this->command->warn('No workspace found — run `php artisan db:seed` (DefaultUserSeeder) first.');

            return;
        }

        $owner = User::query()->find($workspace->owner_id) ?? User::query()->first();

        foreach ($this->accounts() as $data) {
            $account = ConnectedAccount::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'platform' => $data['platform']->value,
                    'handle' => $data['handle'],
                ],
                [
                    'display_name' => $data['display_name'],
                    'avatar_url' => 'https://api.dicebear.com/9.x/initials/svg?seed='.urlencode($data['display_name']).'&backgroundType=gradientLinear',
                    'remote_account_id' => $data['remote_account_id'],
                    'auth_method' => $data['auth_method'],
                    'connected_by_user_id' => $owner?->id,
                    'status' => ConnectedAccountStatus::Active->value,
                    'capabilities' => $data['capabilities'] ?? null,
                    'token_expires_at' => $data['token_expires_at'] ?? null,
                ],
            );

            ConnectedAccountSecret::query()->firstOrCreate(
                ['connected_account_id' => $account->id],
                [
                    'access_token' => $data['access_token'] ?? null,
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'app_password' => $data['app_password'] ?? null,
                    'session' => $data['session'] ?? null,
                ],
            );
        }

        $this->command->info("Seeded {$this->count()} test connected accounts into '{$workspace->name}' (slug: {$workspace->slug}).");
    }

    private function count(): int
    {
        return count($this->accounts());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        // A future expiry keeps OAuth platforms from attempting a token refresh
        // if a publish is triggered. Facebook/Instagram Page tokens are stored
        // non-expiring (null), matching the real connect flow.
        $future = now()->addDays(60);

        return [
            [
                'platform' => Platform::X,
                'handle' => '@test_x',
                'display_name' => 'Test X',
                'remote_account_id' => 'x-test-1',
                'auth_method' => 'oauth',
                'access_token' => 'x-test-token',
                'refresh_token' => 'x-test-refresh',
                'token_expires_at' => $future,
            ],
            [
                'platform' => Platform::Bluesky,
                'handle' => '@test.bsky.social',
                'display_name' => 'Test Bluesky',
                'remote_account_id' => 'did:plc:testbluesky000001',
                'auth_method' => 'app_password',
                'app_password' => 'test-app-password',
                'session' => [
                    'pds' => 'https://bsky.social',
                    'accessJwt' => 'test-access-jwt',
                    'refreshJwt' => 'test-refresh-jwt',
                ],
            ],
            [
                'platform' => Platform::LinkedIn,
                'handle' => 'test-linkedin',
                'display_name' => 'Test LinkedIn',
                'remote_account_id' => 'linkedin-test-1',
                'auth_method' => 'oauth',
                'access_token' => 'linkedin-test-token',
                'refresh_token' => 'linkedin-test-refresh',
                'token_expires_at' => $future,
            ],
            [
                'platform' => Platform::Facebook,
                'handle' => 'Test Page',
                'display_name' => 'Test Page',
                'remote_account_id' => 'fb-page-1',
                'auth_method' => 'oauth',
                // Page tokens minted from a long-lived user token don't expire.
                'access_token' => 'fb-page-token',
                'token_expires_at' => null,
            ],
            [
                'platform' => Platform::Instagram,
                'handle' => '@test_ig',
                'display_name' => 'Test IG',
                'remote_account_id' => 'ig-user-1',
                'auth_method' => 'oauth',
                // Instagram authenticates with its linked Page's token.
                'access_token' => 'fb-page-token',
                'capabilities' => ['page_id' => 'fb-page-1'],
                'token_expires_at' => null,
            ],
            [
                'platform' => Platform::Threads,
                'handle' => '@test_threads',
                'display_name' => 'Test Threads',
                'remote_account_id' => 'th-user-1',
                'auth_method' => 'oauth',
                'access_token' => 'threads-test-token',
                'token_expires_at' => $future,
            ],
        ];
    }
}
