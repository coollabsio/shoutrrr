<?php

use App\Enums\ErrorKind;
use App\Models\PostTarget;
use App\Models\PostTargetAttempt;

test('post target casts publish columns', function () {
    $target = PostTarget::factory()->create([
        'remote_ids' => ['a', 'b'],
        'error_kind' => ErrorKind::RateLimited->value,
        'attempts' => 3,
    ]);

    $target->refresh();

    expect($target->remote_ids)->toBe(['a', 'b'])
        ->and($target->error_kind)->toBe(ErrorKind::RateLimited)
        ->and($target->attempts)->toBe(3);
});

test('post target has many attempt logs', function () {
    $target = PostTarget::factory()->create();
    PostTargetAttempt::factory()->count(2)->create(['post_target_id' => $target->id]);

    expect($target->attemptLogs()->count())->toBe(2)
        ->and($target->attemptLogs->first())->toBeInstanceOf(PostTargetAttempt::class);
});
