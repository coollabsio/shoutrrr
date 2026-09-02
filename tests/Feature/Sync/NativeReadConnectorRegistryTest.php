<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Services\NativeRead\Connectors\BlueskyNativeReadConnector;
use App\Services\NativeRead\Connectors\FacebookNativeReadConnector;
use App\Services\NativeRead\Connectors\InstagramNativeReadConnector;
use App\Services\NativeRead\Connectors\ThreadsNativeReadConnector;
use App\Services\NativeRead\Connectors\XNativeReadConnector;
use App\Services\NativeRead\NativeReadConnectorRegistry;

test('resolves supported platforms', function () {
    $registry = app(NativeReadConnectorRegistry::class);

    expect($registry->for(Platform::X))->toBeInstanceOf(XNativeReadConnector::class);
    expect($registry->for(Platform::Bluesky))->toBeInstanceOf(BlueskyNativeReadConnector::class);
    expect($registry->for(Platform::Threads))->toBeInstanceOf(ThreadsNativeReadConnector::class);
    expect($registry->for(Platform::Instagram))->toBeInstanceOf(InstagramNativeReadConnector::class);
    expect($registry->for(Platform::Facebook))->toBeInstanceOf(FacebookNativeReadConnector::class);
});

test('throws for unsupported platforms', function () {
    $registry = app(NativeReadConnectorRegistry::class);

    expect(fn () => $registry->for(Platform::LinkedIn))->toThrow(RuntimeException::class);
    expect(fn () => $registry->for(Platform::Discord))->toThrow(RuntimeException::class);
});
