<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Post;
use App\Models\PostTarget;
use App\Notifications\Concerns\GatedByPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PostPublishedNotification extends Notification implements ShouldQueue
{
    use GatedByPreferences;
    use Queueable;

    public function __construct(private PostTarget $target)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->gatedVia($notifiable, NotificationType::PostPublished);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $post = $this->target->post()->firstOrFail();

        return $this->databasePayload($post->workspace_id, [
            'event' => NotificationType::PostPublished->value,
            'title' => $this->target->wentToTikTokInbox()
                ? 'Sent to your TikTok inbox'
                : 'Published to '.$this->target->platform->label(),
            'body' => $this->identifierLine($post),
            'href' => route('posts.show', $post),
            'icon' => 'check-circle',
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->target->post()->firstOrFail();

        if ($this->target->wentToTikTokInbox()) {
            return (new MailMessage)
                ->subject('Your post is in your TikTok inbox')
                ->line('Your post is waiting in your TikTok inbox. Open the TikTok app to finish and post it.')
                ->line('"'.$post->excerpt().'"')
                ->action('View post', route('posts.show', $post));
        }

        return (new MailMessage)
            ->subject('Your post was published')
            ->line('Your post went live on '.$this->target->platform->label().'.')
            ->line('"'.$post->excerpt().'"')
            ->action('View post', route('posts.show', $post));
    }

    /**
     * The account handle (when known) followed by a snippet of the post text,
     * so the recipient can tell which post and destination this concerns.
     */
    private function identifierLine(Post $post): string
    {
        $handle = $this->target->account?->handle;
        $excerpt = $post->excerpt();

        return $handle !== null ? $handle.' · '.$excerpt : $excerpt;
    }
}
