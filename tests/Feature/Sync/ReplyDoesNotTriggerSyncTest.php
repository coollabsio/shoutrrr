<?php

declare(strict_types=1);

use App\Jobs\PublishPostTarget;
use App\Jobs\SendReply;

test('the reply publish path does not extend PublishPostTarget', function () {
    // Replies publish through App\Jobs\SendReply; PublishPostTarget (which fires
    // PostTargetPublished) only handles normal post targets. If this ever changes,
    // the sync event would fire for replies and must be guarded.
    expect(class_exists(SendReply::class))->toBeTrue()
        ->and(is_subclass_of(SendReply::class, PublishPostTarget::class))->toBeFalse();
});
