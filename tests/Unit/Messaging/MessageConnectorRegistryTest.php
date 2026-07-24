<?php

use App\Enums\Platform;
use App\Services\Messaging\Connectors\BlueskyDirectMessageConnector;
use App\Services\Messaging\Connectors\XDirectMessageConnector;
use App\Services\Messaging\MessageConnectorRegistry;

test('registry resolves supported platforms', function () {
    $registry = app(MessageConnectorRegistry::class);
    expect($registry->for(Platform::X))->toBeInstanceOf(XDirectMessageConnector::class);
    expect($registry->for(Platform::Bluesky))->toBeInstanceOf(BlueskyDirectMessageConnector::class);
});

test('registry throws for unsupported platforms', function () {
    app(MessageConnectorRegistry::class)->for(Platform::Discord);
})->throws(RuntimeException::class);
