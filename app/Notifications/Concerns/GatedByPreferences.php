<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Enums\NotificationType;
use App\Models\User;

trait GatedByPreferences
{
    /**
     * @return array<int, string>
     */
    protected function gatedVia(object $notifiable, NotificationType $type): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $notifiable->notificationPreferences()->channelsFor($type);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function databasePayload(?string $workspaceId, array $extra): array
    {
        return [...$extra, 'workspace_id' => $workspaceId];
    }
}
