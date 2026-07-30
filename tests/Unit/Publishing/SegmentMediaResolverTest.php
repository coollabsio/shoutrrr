<?php

// tests/Unit/Publishing/SegmentMediaResolverTest.php

use App\Models\PostMedia;
use App\Services\Publishing\SegmentMediaResolver;

function fakeMedia(string $id): PostMedia
{
    $m = new PostMedia;
    $m->id = $id;

    return $m;
}

test('empty placements fall back to all media on section 0', function (): void {
    $out = app(SegmentMediaResolver::class)->resolve(
        sections: ['a', 'b'], sectionSources: [0, 1], segmentBreaks: [],
        placements: [], allMedia: [fakeMedia('m1'), fakeMedia('m2')],
    );

    expect($out[0])->toHaveCount(2);
    expect($out[1] ?? [])->toHaveCount(0);
});

test('media rides the first sub-post of its authored segment', function (): void {
    // Authored segment 0 auto-split into sections 0 and 1; segment 1 (break "b1") is section 2.
    $out = app(SegmentMediaResolver::class)->resolve(
        sections: ['a1', 'a2', 'b'], sectionSources: [0, 0, 1], segmentBreaks: ['b1'],
        placements: [
            ['post_media_id' => 'm1', 'segment_ref' => '__head__', 'position' => 0],
            ['post_media_id' => 'm2', 'segment_ref' => 'b1', 'position' => 0],
        ],
        allMedia: [fakeMedia('m1'), fakeMedia('m2')],
    );

    expect($out[0][0]->id)->toBe('m1'); // first sub-post of authored seg 0
    expect($out[1] ?? [])->toBe([]);    // second sub-post gets nothing
    expect($out[2][0]->id)->toBe('m2'); // authored seg 1
});

test('placement order within a section follows position', function (): void {
    $out = app(SegmentMediaResolver::class)->resolve(
        sections: ['a'], sectionSources: [0], segmentBreaks: [],
        placements: [
            ['post_media_id' => 'm2', 'segment_ref' => '__head__', 'position' => 1],
            ['post_media_id' => 'm1', 'segment_ref' => '__head__', 'position' => 0],
        ],
        allMedia: [fakeMedia('m1'), fakeMedia('m2')],
    );

    expect(array_map(fn (PostMedia $m): string => $m->id, $out[0]))->toBe(['m1', 'm2']);
});
