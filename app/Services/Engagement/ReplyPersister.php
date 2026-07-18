<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Dto\Engagement\ReplyFetchResult;
use App\Jobs\FetchAccountReplies;
use App\Jobs\FetchPostTargetReplies;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Notifications\NewRepliesNotification;
use Illuminate\Support\Facades\Date;

/**
 * Persists an OK reply-fetch result for a single target: upserts the fetched
 * replies, updates polling bookkeeping (reply_fetched_at, empty-streak backoff),
 * recomputes conversation threading, and notifies the author of fresh replies.
 *
 * Shared by the per-target ({@see FetchPostTargetReplies}) and per-account
 * batched ({@see FetchAccountReplies}) fetch jobs.
 */
class ReplyPersister
{
    /**
     * @return list<PostTargetReply> the freshly-inserted inbound replies
     */
    public function persist(PostTarget $target, ReplyFetchResult $result): array
    {
        $post = $target->post()->withoutGlobalScopes()->first();

        if ($post === null) {
            return [];
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

        // Stamp reply_fetched_at only after the upsert loop completes, so a mid-loop
        // failure doesn't leave the target looking freshly polled. Combined with the
        // empty-streak update into one write: grow the streak when a poll comes back
        // dry (cadence backs off), reset it to the fast cadence on any fresh reply.
        $target->forceFill([
            'reply_fetched_at' => Date::now(),
            'reply_fetch_empty_streak' => $inserted === [] ? $target->reply_fetch_empty_streak + 1 : 0,
        ])->save();

        $this->recalculateConversations($target);
        $this->notify($target, $inserted);

        return $inserted;
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
