<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<PostTargetMetric> */
class PostTargetMetricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_target_id' => PostTarget::factory(),
            'captured_at' => Date::now(),
            'likes' => $this->faker->numberBetween(0, 500),
            'comments' => $this->faker->numberBetween(0, 100),
            'reposts' => $this->faker->numberBetween(0, 100),
            'impressions' => $this->faker->numberBetween(0, 5000),
        ];
    }
}
