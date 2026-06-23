<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\Concerns\GatedByPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WorkspaceInviteAcceptedNotification extends Notification implements ShouldQueue
{
    use GatedByPreferences;
    use Queueable;

    public function __construct(
        private WorkspaceInvitation $invitation,
        private User $accepter,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail' => 'sync',
        ];
    }

    /**
     * In-app only — no mail for accepted events.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_values(
            array_filter(
                $this->gatedVia($notifiable, NotificationType::WorkspaceInvite),
                fn (string $channel): bool => $channel !== 'mail',
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->databasePayload($this->invitation->workspace_id, [
            'event' => NotificationType::WorkspaceInvite->value,
            'title' => 'Invitation accepted',
            'body' => $this->accepter->name.' joined '.$this->invitation->workspace->name.'.',
            'href' => route('settings.workspace.members'),
            'icon' => 'user-check',
        ]);
    }
}
