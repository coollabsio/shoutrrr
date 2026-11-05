<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentIntentAction;
use App\Models\AgentIntent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentIntent>
 */
class AgentIntentFactory extends Factory
{
    protected $model = AgentIntent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'post_id' => null,
            'idempotency_key' => fake()->uuid(),
            'action' => AgentIntentAction::PublishNow,
            'request_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
