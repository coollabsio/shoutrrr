<?php

namespace Database\Factories;

use App\Models\PostTarget;
use App\Models\PostTargetAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTargetAttempt>
 */
class PostTargetAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_target_id' => PostTarget::factory(),
            'attempt_no' => 1,
            'status' => 'published',
            'error_kind' => null,
            'error_message' => null,
            'http_status' => 200,
            'response_excerpt' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
