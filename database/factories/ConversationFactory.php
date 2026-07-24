<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'connected_account_id' => ConnectedAccount::factory(),
            'platform' => Platform::Bluesky,
            'remote_conversation_id' => fake()->uuid(),
            'counterpart_handle' => '@'.fake()->userName(),
            'counterpart_name' => fake()->name(),
            'unread_count' => 0,
            'last_message_at' => now(),
        ];
    }
}
