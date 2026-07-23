<?php

namespace Database\Factories;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectedAccount>
 */
class ConnectedAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'platform' => Platform::X->value,
            'handle' => '@'.fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'avatar_url' => fake()->imageUrl(),
            'remote_account_id' => (string) fake()->unique()->numerify('##########'),
            'auth_method' => 'oauth',
            'connected_by_user_id' => User::factory(),
            'status' => ConnectedAccountStatus::Active->value,
            'token_expires_at' => null,
            'last_refreshed_at' => null,
            'refresh_failed_at' => null,
            'refresh_failure_reason' => null,
        ];
    }

    public function bluesky(): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => Platform::Bluesky->value,
            'auth_method' => 'app_password',
            'remote_account_id' => 'did:plc:'.fake()->unique()->bothify('??????????'),
        ]);
    }

    public function discord(): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => Platform::Discord->value,
            'auth_method' => 'webhook',
            'remote_account_id' => (string) fake()->unique()->numerify('##########'),
        ]);
    }

    public function linkedin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => Platform::LinkedIn->value,
            'auth_method' => 'oauth',
            'remote_account_id' => 'person_'.fake()->unique()->bothify('??????????'),
        ]);
    }

    public function linkedinPage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => Platform::LinkedIn->value,
            'auth_method' => 'oauth',
            'remote_account_id' => (string) fake()->unique()->numerify('#######'),
            'capabilities' => ['linkedin_account_type' => 'organization', 'linkedin_engagement' => true],
        ]);
    }

    public function needsAttention(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ConnectedAccountStatus::NeedsAttention->value,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'disabled_at' => now()->subDay(),
        ]);
    }
}
