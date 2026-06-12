<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTarget>
 */
class PostTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'connected_account_id' => ConnectedAccount::factory(),
            'platform' => Platform::X->value,
            'sections' => ['Hello world'],
            'content_override' => null,
            'auto_split' => true,
            'status' => PostTargetStatus::Pending->value,
        ];
    }
}
