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

class BlueskyEngagementConnector implements EngagementConnector
{
    public function fetchReplies(ConnectedAccount $account, PostTarget $target, array $credentials, ?CarbonImmutable $since): ReplyFetchResult
    {
        return ReplyFetchResult::unsupported();
    }

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials): ReplyPostResult
    {
        return ReplyPostResult::unsupported();
    }
}
