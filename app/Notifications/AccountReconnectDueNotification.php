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

/**
 * Warns that an account still works but its provider will stop renewing its
 * access on a known date, so it has to be reconnected before then. Delivered
 * under the same preferences as an account that already needs attention.
 */
class AccountReconnectDueNotification extends Notification implements ShouldQueue
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
            'title' => $this->account->platform->label().' account needs reconnecting soon',
            'body' => $this->message(),
            'href' => route('accounts.index'),
            'icon' => 'plug',
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A connected account needs reconnecting soon')
            ->line($this->message())
            ->action('Reconnect account', route('accounts.index'));
    }

    private function message(): string
    {
        $date = $this->account->refresh_token_expires_at?->toFormattedDateString() ?? 'soon';

        return $this->account->platform->label().' stops renewing access for '.$this->account->handle.' on '.$date
            .'. Reconnect it before then to keep publishing without interruption.';
    }
}
