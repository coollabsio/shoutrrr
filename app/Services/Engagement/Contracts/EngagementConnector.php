<?php

declare(strict_types=1);

namespace App\Services\Engagement\Contracts;

use App\Dto\Engagement\ReplyActionResult;
use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use Carbon\CarbonImmutable;

interface EngagementConnector
{
    /**
     * Fetch replies on the target's published post(s), optionally only those
     * created after $since (incremental polling).
     *
     * @param  array<string, mixed>  $credentials
     */
    public function fetchReplies(
        ConnectedAccount $account,
        PostTarget $target,
        array $credentials,
        ?CarbonImmutable $since,
    ): ReplyFetchResult;

    /**
     * Post a reply back to the platform, threaded under $parent.
     *
     * @param  array<string, mixed>  $credentials
     * @param  list<PostMedia>  $media
     */
    public function postReply(
        ConnectedAccount $account,
        PostTargetReply $parent,
        string $text,
        array $credentials,
        array $media = [],
    ): ReplyPostResult;

    /**
     * Like $reply on the platform. On success the result's `remoteId` is the
     * platform's like-record id where one exists (needed to unlike later).
     *
     * @param  array<string, mixed>  $credentials
     */
    public function likeReply(
        ConnectedAccount $account,
        PostTargetReply $reply,
        array $credentials,
    ): ReplyActionResult;

    /**
     * Remove a like previously placed on $reply. $likeRemoteId is the stored
     * like-record id (may be null for platforms that unlike by subject id).
     *
     * @param  array<string, mixed>  $credentials
     */
    public function unlikeReply(
        ConnectedAccount $account,
        PostTargetReply $reply,
        ?string $likeRemoteId,
        array $credentials,
    ): ReplyActionResult;

    /**
     * Delete one of our own replies ($reply->is_ours) from the platform.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function deleteReply(
        ConnectedAccount $account,
        PostTargetReply $reply,
        array $credentials,
    ): ReplyActionResult;
}
