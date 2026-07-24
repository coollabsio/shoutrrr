<?php

use App\Enums\Platform;

test('only X, LinkedIn and Bluesky support native reposting', function (Platform $platform, bool $expected): void {
    expect($platform->supportsRepost())->toBe($expected);
})->with([
    'x' => [Platform::X, true],
    'linkedin' => [Platform::LinkedIn, true],
    'bluesky' => [Platform::Bluesky, true],
    'facebook' => [Platform::Facebook, false],
    'instagram' => [Platform::Instagram, false],
    'threads' => [Platform::Threads, false],
    'discord' => [Platform::Discord, false],
]);
