<?php

declare(strict_types=1);

namespace App\Services\Engagement\Contracts;

use App\Dto\Engagement\ReplyPostResult;
use App\Models\ConnectedAccount;

/**
 * For a platform that builds a reply in a container before it publishes it.
 * SendReply calls this to continue a reply instead of creating a second one.
 */
interface PublishesReplyContainers
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function publishContainer(ConnectedAccount $account, string $containerId, array $credentials): ReplyPostResult;
}
