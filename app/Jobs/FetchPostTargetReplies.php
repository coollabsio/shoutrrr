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
            $credentials = in_array($account->platform, [Platform::X, Platform::Bluesky, Platform::LinkedIn], true)
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

        $this->notify($target, $inserted);
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
