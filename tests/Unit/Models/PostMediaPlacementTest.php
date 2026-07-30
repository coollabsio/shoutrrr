<?php

declare(strict_types=1);

use App\Models\PostMediaPlacement;
use App\Models\PostTarget;

test('a target has ordered placements linking media', function (): void {
    $target = PostTarget::factory()->create();
    $b = PostMediaPlacement::factory()->for($target, 'target')->create(['position' => 1]);
    $a = PostMediaPlacement::factory()->for($target, 'target')->create(['position' => 0]);

    $ordered = $target->placements()->get();

    expect($ordered->pluck('id')->all())->toBe([$a->id, $b->id]);
    expect($a->media)->not->toBeNull();
});
