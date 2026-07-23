<?php

use App\Enums\Platform;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use App\Services\Publishing\Connectors\LinkedInConnector;
use App\Services\Publishing\Connectors\XConnector;
use App\Services\Repost\Contracts\RepostConnector;
use App\Services\Repost\RepostConnectorRegistry;

test('registry rejects platforms without native repost', function (): void {
    app(RepostConnectorRegistry::class)->for(Platform::Instagram);
})->throws(InvalidArgumentException::class);

test('registry resolves a repost connector per supported platform', function (Platform $platform, string $class): void {
    $connector = app(RepostConnectorRegistry::class)->for($platform);

    expect($connector)->toBeInstanceOf(RepostConnector::class)
        ->and($connector)->toBeInstanceOf($class);
})->with([
    'x' => [Platform::X, XConnector::class],
    'linkedin' => [Platform::LinkedIn, LinkedInConnector::class],
    'bluesky' => [Platform::Bluesky, BlueskyPublishConnector::class],
]);
