<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'account_set_id' => null,
            'author_id' => User::factory(),
            'base_text' => $text = fake()->sentence(),
            'segments' => [$text],
            'mentions' => null,
            'status' => PostStatus::Draft->value,
            'scheduled_at' => null,
            'published_at' => null,
            'deleted_at' => null,
        ];
    }
}
