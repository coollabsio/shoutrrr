<?php

use App\Enums\Platform;
use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use App\Services\Publishing\Connectors\FacebookConnector;
use App\Services\Publishing\Connectors\DiscordPublishConnector;
use App\Services\Publishing\Connectors\GoogleBusinessProfileConnector;
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
        ->and($registry->for(Platform::Threads))->toBeInstanceOf(ThreadsConnector::class)
        ->and($registry->for(Platform::Discord))->toBeInstanceOf(DiscordPublishConnector::class)
        ->and($registry->for(Platform::GoogleBusinessProfile))->toBeInstanceOf(GoogleBusinessProfileConnector::class);
});

test('google business profile connector fails safely while publishing is unavailable', function () {
    $context = new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::GoogleBusinessProfile->value]),
        segments: ['A local post'],
        media: [],
        account: ConnectedAccount::factory()->create(),
        credentials: [],
    );

    $result = app(PublishConnectorRegistry::class)
        ->for(Platform::GoogleBusinessProfile)
        ->publish($context);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Unsupported)
        ->and($result->errorMessage)->toBe('Google Business Profile publishing is not available yet.');
});
