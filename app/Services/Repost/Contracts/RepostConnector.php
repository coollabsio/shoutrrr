<?php

declare(strict_types=1);

namespace App\Services\Repost\Contracts;

use App\Dto\Publishing\PublishResult;
use App\Dto\Repost\RepostContext;

interface RepostConnector
{
    /**
     * Natively re-share the account's own already-published post. On success the
     * returned PublishResult's first remoteId is stored as `repost_remote_id`.
     */
    public function repost(RepostContext $context): PublishResult;
}
