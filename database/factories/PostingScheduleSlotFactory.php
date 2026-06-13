<?php

namespace Database\Factories;

use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<PostingScheduleSlot>
 */
class PostingScheduleSlotFactory extends Factory
{
    #[Override]
    protected $model = PostingScheduleSlot::class;

    public function definition(): array
    {
        return [
            'posting_schedule_id' => PostingSchedule::factory(),
            'weekday' => fake()->numberBetween(0, 6),
            'hour' => fake()->numberBetween(0, 23),
            'position' => 0,
        ];
    }
}
