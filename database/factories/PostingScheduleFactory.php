<?php

namespace Database\Factories;

use App\Models\PostingSchedule;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<PostingSchedule>
 */
class PostingScheduleFactory extends Factory
{
    #[Override]
    protected $model = PostingSchedule::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'timezone' => 'UTC',
        ];
    }
}
