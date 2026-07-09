<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\Platform;
use App\Services\Metrics\Connectors\BlueskyMetricsConnector;
use App\Services\Metrics\Connectors\FacebookMetricsConnector;
use App\Services\Metrics\Connectors\InstagramMetricsConnector;
use App\Services\Metrics\Connectors\LinkedInMetricsConnector;
use App\Services\Metrics\Connectors\ThreadsMetricsConnector;
use App\Services\Metrics\Connectors\XMetricsConnector;
use App\Services\Metrics\Contracts\MetricsConnector;

class MetricsConnectorRegistry
{
    public function for(Platform $platform): MetricsConnector
    {
        return match ($platform) {
            Platform::X => app(XMetricsConnector::class),
            Platform::Bluesky => app(BlueskyMetricsConnector::class),
            Platform::LinkedIn => app(LinkedInMetricsConnector::class),
            Platform::Facebook => app(FacebookMetricsConnector::class),
            Platform::Instagram => app(InstagramMetricsConnector::class),
            Platform::Threads => app(ThreadsMetricsConnector::class),
        };
    }
}
