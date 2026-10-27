<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\ConnectedAccount;
use App\Notifications\Concerns\GatedByPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountNeedsAttentionNotification extends Notification implements ShouldQueue
{
    use GatedByPreferences;
    use Queueable;

    public function __construct(
        private ConnectedAccount $account,
        private string $workspaceId,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->gatedVia($notifiable, NotificationType::AccountNeedsAttention);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->databasePayload($this->workspaceId, [
            'event' => NotificationType::AccountNeedsAttention->value,
            'title' => $this->account->platform->label().' account needs attention',
            'body' => $this->account->handle.' needs to be reconnected. Publishing and sync pipelines using this account are paused.',
            'href' => route('accounts.index'),
            'icon' => 'plug',
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A connected account needs attention')
            ->line($this->account->handle.' needs to be reconnected. Publishing and sync pipelines using this account are paused.')
            ->action('Reconnect account', route('accounts.index'));
    }
}
