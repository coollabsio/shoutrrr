<?php

use App\Enums\Platform;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use App\Services\Publishing\Connectors\FacebookConnector;
use App\Services\Publishing\Connectors\InstagramConnector;
use App\Services\Publishing\Connectors\LinkedInConnector;
use App\Services\Publishing\Connectors\ThreadsConnector;
use App\Services\Publishing\Connectors\XConnector;
use App\Services\Publishing\PublishConnectorRegistry;

test('registry resolves each platform to its connector', function () {
    $registry = app(PublishConnectorRegistry::class);

    expect($registry->for(Platform::X))->toBeInstanceOf(XConnector::class)
        ->and($registry->for(Platform::Bluesky))->toBeInstanceOf(BlueskyPublishConnector::class)
        ->and($registry->for(Platform::LinkedIn))->toBeInstanceOf(LinkedInConnector::class)
        ->and($registry->for(Platform::Facebook))->toBeInstanceOf(FacebookConnector::class)
        ->and($registry->for(Platform::Instagram))->toBeInstanceOf(InstagramConnector::class)
        ->and($registry->for(Platform::Threads))->toBeInstanceOf(ThreadsConnector::class);
});
