<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Models\PostTarget;
use App\Services\Publishing\Contracts\PublishConnector;

class GoogleBusinessProfileConnector implements PublishConnector
{
    public function publish(PublishContext $context): PublishResult
    {
        return PublishResult::failure(
            ErrorKind::Unsupported,
            'Google Business Profile publishing is not available yet.',
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function delete(PostTarget $target, array $credentials): void
    {
    }
}
