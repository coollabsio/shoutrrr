<?php

declare(strict_types=1);

namespace App\Services\Repost;

use App\Enums\Platform;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use App\Services\Publishing\Connectors\LinkedInConnector;
use App\Services\Publishing\Connectors\XConnector;
use App\Services\Repost\Contracts\RepostConnector;
use InvalidArgumentException;

class RepostConnectorRegistry
{
    public function for(Platform $platform): RepostConnector
    {
        return match ($platform) {
            Platform::X => app(XConnector::class),
            Platform::LinkedIn => app(LinkedInConnector::class),
            Platform::Bluesky => app(BlueskyPublishConnector::class),
            default => throw new InvalidArgumentException("Platform {$platform->value} does not support reposting."),
        };
    }
}
