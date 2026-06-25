<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\PostTargetReply;
use App\Notifications\Concerns\GatedByPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReplyFailedNotification extends Notification implements ShouldQueue
{
    use GatedByPreferences;
    use Queueable;

    public function __construct(private PostTargetReply $reply)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->gatedVia($notifiable, NotificationType::ReplyFailed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->databasePayload($this->reply->workspace_id, [
            'event' => NotificationType::ReplyFailed->value,
            'title' => 'Reply failed to send',
            'body' => $this->reply->text,
            'href' => '/engagement',
            'icon' => 'alert-triangle',
        ]);
    }
}
