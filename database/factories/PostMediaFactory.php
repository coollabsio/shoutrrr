<?php

namespace Database\Factories;

use App\Models\PostMedia;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostMedia>
 */
class PostMediaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'post_id' => null,
            'disk' => 'public',
            'path' => 'media/'.fake()->uuid().'.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1000, 500000),
            'width' => 1200,
            'height' => 800,
            'alt_text' => null,
            'position' => 0,
            'kind' => 'image',
            'duration_seconds' => null,
        ];
    }

    public function video(): static
    {
        return $this->state(fn (): array => [
            'kind' => 'video',
            'mime' => 'video/mp4',
            'duration_seconds' => $this->faker->numberBetween(3, 120),
            'width' => 1280,
            'height' => 720,
        ]);
    }
}
