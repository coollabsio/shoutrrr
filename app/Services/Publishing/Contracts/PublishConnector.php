<?php

declare(strict_types=1);

namespace App\Services\Publishing\Contracts;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Models\PostTarget;

interface PublishConnector
{
    public function publish(PublishContext $context): PublishResult;

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function delete(PostTarget $target, array $credentials): void;
}
