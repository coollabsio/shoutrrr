<?php

use App\Enums\Platform;
use App\Services\Posts\PostSplitter;

test('section sources map each section back to its authored segment', function (): void {
    // Two authored segments; the first is long enough to auto-split into 2 on X.
    $long = str_repeat('word ', 80); // > 280 chars
    $result = app(PostSplitter::class)->split([$long, 'second'], Platform::X, true);

    expect(count($result->sections))->toBeGreaterThan(2);
    // Every section from authored segment 0 precedes the one from segment 1.
    expect($result->sectionSources[count($result->sectionSources) - 1])->toBe(1);
    expect($result->sectionSources[0])->toBe(0);
});

test('an empty media-bearing segment is preserved as a section', function (): void {
    $result = app(PostSplitter::class)->split(['hello', ''], Platform::X, true, null, mediaSegments: [1]);

    expect($result->sections)->toContain('');
    // The kept empty section is sourced from authored segment index 1.
    $emptyIndex = array_search('', $result->sections, true);
    expect($result->sectionSources[$emptyIndex])->toBe(1);
});
