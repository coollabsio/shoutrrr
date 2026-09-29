<?php

declare(strict_types=1);

namespace App\Services\NativeRead\Contracts;

use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\NativeRead\RecentPostsResult;
use App\Models\ConnectedAccount;

interface NativeReadConnector
{
    /**
     * Fetch the account's own recent original posts, newest-first.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult;
}
