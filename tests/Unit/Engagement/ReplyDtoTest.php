<?php

use App\Dto\Engagement\FetchedReply;
use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Services\Engagement\Connectors\BlueskyEngagementConnector;
use App\Services\Engagement\EngagementConnectorRegistry;
use Carbon\CarbonImmutable;

test('fetch result ok carries replies', function () {
    $reply = new FetchedReply('r1', 'c1', null, '@a', 'A', null, 'hi', CarbonImmutable::now());
    $result = ReplyFetchResult::ok([$reply]);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(1);
    expect($result->replies[0]->remoteReplyId)->toBe('r1');
});

test('fetch result failed is not ok', function () {
    expect(ReplyFetchResult::unsupported('no access')->isOk())->toBeFalse();
    expect(ReplyFetchResult::unsupported('no access')->status)->toBe(EngagementStatus::Unsupported);
});

test('post result ok carries remote id', function () {
    $result = ReplyPostResult::ok('r9', 'c9');
    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('r9');
    expect($result->remoteCid)->toBe('c9');
});

test('registry resolves the bluesky connector', function () {
    expect(app(EngagementConnectorRegistry::class)->for(Platform::Bluesky))
        ->toBeInstanceOf(BlueskyEngagementConnector::class);
});
