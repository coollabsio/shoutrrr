<?php

declare(strict_types=1);

namespace App\Services\Metrics\Connectors;

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Contracts\MetricsConnector;

/**
 * LinkedIn does not expose engagement/follower analytics for personal member posts via
 * the `w_member_social` scope (that needs Organization Page permissions). Reports
 * `unsupported` (terminal) so the poller never burns calls here.
 */
class LinkedInMetricsConnector implements MetricsConnector
{
    private const string REASON = 'LinkedIn personal-account analytics are not available via the API.';

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        return PostMetricsResult::unsupported(self::REASON);
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        return AccountMetricsResult::unsupported(self::REASON);
    }
}
