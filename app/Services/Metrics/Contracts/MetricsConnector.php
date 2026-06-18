<?php

declare(strict_types=1);

namespace App\Services\Metrics\Contracts;

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;

interface MetricsConnector
{
    /** @param array<string, mixed> $credentials */
    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult;

    /** @param array<string, mixed> $credentials */
    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult;
}
