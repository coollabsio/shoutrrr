<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectMessage>
 */
class DirectMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'conversation_id' => Conversation::factory(),
            'remote_message_id' => fake()->uuid(),
            'direction' => MessageDirection::Inbound,
            'text' => fake()->sentence(),
            'is_ours' => false,
            'remote_created_at' => now(),
        ];
    }
}
