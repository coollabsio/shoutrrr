<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectedAccountNativeWatch>
 */
class ConnectedAccountNativeWatchFactory extends Factory
{
    protected $model = ConnectedAccountNativeWatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connected_account_id' => ConnectedAccount::factory(),
            'workspace_id' => Workspace::factory(),
            'enabled_at' => now(),
            'last_seen_remote_id' => null,
            'last_polled_at' => null,
        ];
    }
}
