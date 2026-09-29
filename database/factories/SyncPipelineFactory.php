<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConnectedAccount;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncPipeline>
 */
class SyncPipelineFactory extends Factory
{
    protected $model = SyncPipeline::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'source_connected_account_id' => ConnectedAccount::factory(),
            'name' => fake()->words(3, true),
            'enabled' => true,
            'created_by' => User::factory(),
        ];
    }
}
