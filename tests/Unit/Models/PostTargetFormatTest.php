<?php

use App\Enums\PostFormat;
use App\Models\PostTarget;

test('post target format defaults to feed', function () {
    $target = PostTarget::factory()->create();

    expect($target->fresh()->format)->toBe(PostFormat::Feed);
});

test('post target format casts to the PostFormat enum', function () {
    $target = PostTarget::factory()->create(['format' => 'story']);

    expect($target->fresh()->format)->toBe(PostFormat::Story);
});
