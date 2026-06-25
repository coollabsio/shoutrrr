<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\PostTarget;
use App\Notifications\Concerns\GatedByPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewRepliesNotification extends Notification implements ShouldQueue
{
    use GatedByPreferences;
    use Queueable;

    public function __construct(private PostTarget $target, private int $count)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->gatedVia($notifiable, NotificationType::NewReplies);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $post = $this->target->post()->firstOrFail();
        $handle = $this->target->account?->handle;
        $noun = $this->count === 1 ? 'reply' : 'replies';

        // Task 10 introduces the engagement.index named route; switch to route() once it exists.
        $href = '/engagement?target='.$this->target->id.'&unread=1';

        return $this->databasePayload($post->workspace_id, [
            'event' => NotificationType::NewReplies->value,
            'title' => $this->count.' new '.$noun.' on '.$this->target->platform->label(),
            'body' => ($handle !== null ? $handle.' · ' : '').$post->excerpt(),
            'href' => $href,
            'icon' => 'message-circle',
        ]);
    }
}
