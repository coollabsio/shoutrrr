<?php

declare(strict_types=1);

namespace App\Services\Engagement\Contracts;

use App\Dto\Engagement\ReplyFetchResult;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;

/**
 * Optional capability for connectors that can fetch replies for many of an
 * account's posts in a single API call (e.g. X's search OR-combines conversation
 * ids), turning per-post polling from O(posts) into O(posts ÷ batch).
 */
interface BatchEngagementConnector
{
    /**
     * Fetch replies for several root posts at once. Returns a result keyed by
     * each input root id; ids with no replies get an OK result with an empty
     * list, and on a failed request every id in the failing batch shares that
     * failure result (so the caller can react to a rate-limit / auth error).
     *
     * @param  list<string>  $rootIds
     * @param  array<string, mixed>  $credentials
     * @return array<string, ReplyFetchResult>
     */
    public function fetchRepliesForConversations(
        ConnectedAccount $account,
        array $rootIds,
        array $credentials,
        ?CarbonImmutable $since,
    ): array;
}
