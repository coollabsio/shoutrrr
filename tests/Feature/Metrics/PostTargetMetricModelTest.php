<?php

use App\Models\PostTarget;
use App\Models\PostTargetMetric;

test('a post target has many metric snapshots ordered by capture time', function () {
    $target = PostTarget::factory()->create();
    PostTargetMetric::factory()->for($target, 'target')->create(['likes' => 3]);

    expect($target->metrics)->toHaveCount(1);
    expect($target->metrics->first()->likes)->toBe(3);
});
