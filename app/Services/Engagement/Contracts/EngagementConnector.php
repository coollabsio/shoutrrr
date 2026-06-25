<?php

declare(strict_types=1);

namespace App\Services\Engagement\Contracts;

use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Models\ConnectedAccount;
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
     */
    public function postReply(
        ConnectedAccount $account,
        PostTargetReply $parent,
        string $text,
        array $credentials,
    ): ReplyPostResult;
}
