<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use App\Services\Publishing\Connectors\DiscordPublishConnector;
use App\Services\Publishing\Connectors\FacebookConnector;
use App\Services\Publishing\Connectors\InstagramConnector;
use App\Services\Publishing\Connectors\LinkedInConnector;
use App\Services\Publishing\Connectors\ThreadsConnector;
use App\Services\Publishing\Connectors\TikTokConnector;
use App\Services\Publishing\Connectors\XConnector;
use App\Services\Publishing\Contracts\PublishConnector;

class PublishConnectorRegistry
{
    public function for(Platform $platform): PublishConnector
    {
        return match ($platform) {
            Platform::X => app(XConnector::class),
            Platform::Bluesky => app(BlueskyPublishConnector::class),
            Platform::LinkedIn => app(LinkedInConnector::class),
            Platform::Facebook => app(FacebookConnector::class),
            Platform::Instagram => app(InstagramConnector::class),
            Platform::Threads => app(ThreadsConnector::class),
            Platform::Discord => app(DiscordPublishConnector::class),
            Platform::TikTok => app(TikTokConnector::class),
        };
    }
}
