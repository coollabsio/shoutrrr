<?php

declare(strict_types=1);

use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Bus;

test('MediaProcessing is retryable', function (): void {
    expect(ErrorKind::MediaProcessing->isRetryable())->toBeTrue();
});

test('MediaProcessing re-dispatches without burning the publish attempt budget', function (): void {
    Bus::fake();

    $target = publishTarget();
    $target->forceFill(['attempts' => 2, 'media_upload_state' => ['_polls' => 3]])->save();

    bindConnector(PublishResult::failure(ErrorKind::MediaProcessing, 'video still processing', retryAfter: 15));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();

    // attempts must NOT increase — it should be decremented back to neutralize the handle() increment
    expect($target->attempts)->toBe(2);
    expect($target->media_upload_state['_polls'])->toBe(4);
    expect($target->status)->toBe(PostTargetStatus::Publishing);

    Bus::assertDispatched(PublishPostTarget::class);
});

test('MediaProcessing terminates after MAX_MEDIA_POLLS exceeded', function (): void {
    Bus::fake();

    $target = publishTarget();
    $target->forceFill(['attempts' => 2, 'media_upload_state' => ['_polls' => 40]])->save();

    bindConnector(PublishResult::failure(ErrorKind::MediaProcessing, 'video still processing'));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed);
    Bus::assertNotDispatched(PublishPostTarget::class);
});
