<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceMention;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMention>
 */
class WorkspaceMentionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = '@'.fake()->unique()->userName();

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'handles' => [
                'x' => $name,
                'bluesky' => $name,
                'linkedin' => $name,
            ],
        ];
    }
}
