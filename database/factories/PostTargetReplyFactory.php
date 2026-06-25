<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PostTargetReply>
 */
class PostTargetReplyFactory extends Factory
{
    protected $model = PostTargetReply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => fake()->uuid(),
            'post_target_id' => PostTarget::factory(),
            'platform' => Platform::Bluesky,
            'remote_reply_id' => 'at://reply/'.Str::random(8),
            'remote_cid' => 'cid-'.Str::random(8),
            'parent_remote_id' => null,
            'author_handle' => '@'.fake()->userName(),
            'author_name' => fake()->name(),
            'author_avatar_url' => fake()->imageUrl(),
            'text' => fake()->sentence(),
            'remote_created_at' => now()->subMinutes(fake()->numberBetween(1, 600)),
            'read_at' => null,
            'status' => ReplyStatus::Pending,
            'our_reply_remote_id' => null,
            'is_ours' => false,
            'fetched_at' => now(),
        ];
    }
}
