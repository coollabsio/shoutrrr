<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Enums\Platform;
use App\Services\Messaging\Connectors\BlueskyDirectMessageConnector;
use App\Services\Messaging\Connectors\FacebookDirectMessageConnector;
use App\Services\Messaging\Connectors\InstagramDirectMessageConnector;
use App\Services\Messaging\Connectors\XDirectMessageConnector;
use App\Services\Messaging\Contracts\DirectMessageConnector;
use RuntimeException;

class MessageConnectorRegistry
{
    public function for(Platform $platform): DirectMessageConnector
    {
        return match ($platform) {
            Platform::X => app(XDirectMessageConnector::class),
            Platform::Bluesky => app(BlueskyDirectMessageConnector::class),
            Platform::Instagram => app(InstagramDirectMessageConnector::class),
            Platform::Facebook => app(FacebookDirectMessageConnector::class),
            default => throw new RuntimeException("{$platform->value} does not support direct messages."),
        };
    }
}
