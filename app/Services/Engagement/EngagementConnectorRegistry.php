<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\Platform;
use App\Services\Engagement\Connectors\BlueskyEngagementConnector;
use App\Services\Engagement\Connectors\LinkedInEngagementConnector;
use App\Services\Engagement\Connectors\XEngagementConnector;
use App\Services\Engagement\Contracts\EngagementConnector;

class EngagementConnectorRegistry
{
    public function for(Platform $platform): EngagementConnector
    {
        return match ($platform) {
            Platform::X => app(XEngagementConnector::class),
            Platform::Bluesky => app(BlueskyEngagementConnector::class),
            Platform::LinkedIn => app(LinkedInEngagementConnector::class),
        };
    }
}
