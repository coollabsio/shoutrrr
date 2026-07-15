<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Notifications\NewRepliesNotification;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Date;

class FetchPostTargetReplies implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Hold the uniqueness lock at most this long, so a stuck worker can't block
     * the next cadence window forever.
     */
    public int $uniqueFor = 300;

    public function __construct(public PostTarget $target) {}

    /**
     * One in-flight fetch per target: overlapping scheduler ticks (or a slow
     * worker) must not double-dispatch the same target.
     */
    public function uniqueId(): string
    {
        return $this->target->id;
    }

    /**
     * Throttle outbound fetch calls per platform so a large post list can't
     * trip the platform's own rate limits.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited("engagement-{$this->target->platform->value}")];
    }

    /**
     * Back off between retries on transient (thrown) errors.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(EngagementConnectorRegistry $registry, TokenManager $tokens): void
    {
        if (! config('engagement.enabled')) {
            return;
        }

        $target = $this->target->fresh();

        if ($target === null) {
            return;
        }

        $account = $target->account()->withoutGlobalScopes()->first();
        $post = $target->post()->withoutGlobalScopes()->first();

        if ($account === null || $post === null) {
            return;
        }

        try {
            $credentials = in_array($account->platform, [Platform::X, Platform::Bluesky, Platform::LinkedIn, Platform::Facebook, Platform::Instagram, Platform::Threads], true)
                ? $tokens->fresh($account)
                : [];
        } catch (TokenRefreshException) {
            return;
        }

        $since = PostTargetReply::withoutGlobalScopes()
            ->where('post_target_id', $target->id)
            ->where('is_ours', false)
            ->max('remote_created_at');

        $result = $registry->for($target->platform)->fetchReplies(
            $account,
            $target,
            $credentials,
            $since !== null ? Date::parse($since)->toImmutable() : null,
        );

        if (! $result->isOk()) {
            return;
        }

        $target->forceFill(['reply_fetched_at' => Date::now()])->save();

        $inserted = [];

        foreach ($result->replies as $fetched) {
            $reply = PostTargetReply::withoutGlobalScopes()->updateOrCreate(
                ['post_target_id' => $target->id, 'remote_reply_id' => $fetched->remoteReplyId],
                [
                    'workspace_id' => $post->workspace_id,
                    'platform' => $target->platform,
                    'remote_cid' => $fetched->remoteCid,
                    'parent_remote_id' => $fetched->parentRemoteId,
                    'author_handle' => $fetched->authorHandle,
                    'author_name' => $fetched->authorName,
                    'author_avatar_url' => $fetched->authorAvatarUrl,
                    'text' => $fetched->text,
                    'remote_created_at' => $fetched->remoteCreatedAt,
                    'fetched_at' => Date::now(),
                ],
            );

            if ($reply->wasRecentlyCreated) {
                $inserted[] = $reply;
            }
        }

        // Grow the empty-streak so the cadence backs off posts that keep coming
        // back dry; any fresh reply resets it to the fast fresh-post cadence.
        $target->forceFill([
            'reply_fetch_empty_streak' => $inserted === [] ? $target->reply_fetch_empty_streak + 1 : 0,
        ])->save();

        $this->recalculateConversations($target);

        $this->notify($target, $inserted);
    }

    private function recalculateConversations(PostTarget $target): void
    {
        $replies = PostTargetReply::withoutGlobalScopes()
            ->where('post_target_id', $target->id)
            ->get(['id', 'post_target_id', 'remote_reply_id', 'parent_remote_id', 'conversation_remote_id']);

        $byRemoteId = $replies->keyBy('remote_reply_id');
        $resolved = [];

        $conversationFor = function (PostTargetReply $reply, array $visited = []) use (&$conversationFor, &$resolved, $byRemoteId, $target): string {
            if (isset($resolved[$reply->remote_reply_id])) {
                return $resolved[$reply->remote_reply_id];
            }

            if (
                $reply->parent_remote_id === null
                || $reply->parent_remote_id === $target->remote_id
                || in_array($reply->parent_remote_id, $visited, true)
                || ! $byRemoteId->has($reply->parent_remote_id)
            ) {
                return $resolved[$reply->remote_reply_id] = $reply->remote_reply_id;
            }

            $visited[] = $reply->remote_reply_id;

            return $resolved[$reply->remote_reply_id] = $conversationFor($byRemoteId->get($reply->parent_remote_id), $visited);
        };

        $replies->each(function (PostTargetReply $reply) use ($conversationFor): void {
            $conversationRemoteId = $conversationFor($reply);

            if ($reply->conversation_remote_id === $conversationRemoteId) {
                return;
            }

            $reply->forceFill(['conversation_remote_id' => $conversationRemoteId])->saveQuietly();
        });
    }

    /**
     * @param  list<PostTargetReply>  $inserted
     */
    private function notify(PostTarget $target, array $inserted): void
    {
        if ($inserted === []) {
            return;
        }

        $author = $target->post()->withoutGlobalScopes()->first()?->author()->first();

        $author?->notify(new NewRepliesNotification($target, count($inserted)));
    }
}
