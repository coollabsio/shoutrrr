<?php

declare(strict_types=1);

namespace App\Services\Engagement\Connectors;

use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Contracts\EngagementConnector;
use Carbon\CarbonImmutable;

/**
 * LinkedIn comment read/write needs elevated partner permissions the current
 * `w_member_social` scope lacks, so engagement is reported as unsupported until
 * that access lands. Kept as a real connector so the registry and UI degrade
 * cleanly rather than special-casing the platform.
 */
class LinkedInEngagementConnector implements EngagementConnector
{
    private const string MESSAGE = 'LinkedIn replies require partner comment permissions that are not yet available.';

    public function fetchReplies(ConnectedAccount $account, PostTarget $target, array $credentials, ?CarbonImmutable $since): ReplyFetchResult
    {
        return ReplyFetchResult::unsupported(self::MESSAGE);
    }

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials): ReplyPostResult
    {
        return ReplyPostResult::unsupported(self::MESSAGE);
    }
}
