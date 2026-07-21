<?php

namespace Database\Factories;

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostFormat;
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
            'format' => PostFormat::Feed->value,
            'status' => PostTargetStatus::Pending->value,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PostTargetStatus::Failed->value,
            'error_kind' => ErrorKind::Validation->value,
            'error_message' => 'rejected',
            'attempts' => 1,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PostTargetStatus::Published->value,
            'remote_id' => 'remote-'.fake()->uuid(),
            'posted_at' => now(),
        ]);
    }
}
