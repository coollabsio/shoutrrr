<?php

declare(strict_types=1);

namespace App\Services\NativeRead;

use App\Enums\Platform;
use App\Services\NativeRead\Connectors\BlueskyNativeReadConnector;
use App\Services\NativeRead\Connectors\FacebookNativeReadConnector;
use App\Services\NativeRead\Connectors\InstagramNativeReadConnector;
use App\Services\NativeRead\Connectors\ThreadsNativeReadConnector;
use App\Services\NativeRead\Connectors\XNativeReadConnector;
use App\Services\NativeRead\Contracts\NativeReadConnector;
use RuntimeException;

class NativeReadConnectorRegistry
{
    public function for(Platform $platform): NativeReadConnector
    {
        return match ($platform) {
            Platform::X => app(XNativeReadConnector::class),
            Platform::Bluesky => app(BlueskyNativeReadConnector::class),
            Platform::Threads => app(ThreadsNativeReadConnector::class),
            Platform::Instagram => app(InstagramNativeReadConnector::class),
            Platform::Facebook => app(FacebookNativeReadConnector::class),
            Platform::LinkedIn, Platform::Discord => throw new RuntimeException($platform->label().' does not support native post reads.'),
        };
    }
}
