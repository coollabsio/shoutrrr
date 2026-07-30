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

test('media targeting a pruned authored segment walks back (through a gap) to the nearest earlier surviving section', function (): void {
    // Authored segments: 0 (survives as section 0), 1 (break "b1", pruned), 2 (break "c1", pruned),
    // 3 (break "d1", survives as section 1). A placement on "c1" must walk back through the
    // missing authored-1 entry and land on authored-0's section, not authored-3's.
    $out = app(SegmentMediaResolver::class)->resolve(
        sections: ['a0', 'd'], sectionSources: [0, 3], segmentBreaks: ['b1', 'c1', 'd1'],
        placements: [
            ['post_media_id' => 'm1', 'segment_ref' => '__head__', 'position' => 0],
            ['post_media_id' => 'm2', 'segment_ref' => 'c1', 'position' => 1],
            ['post_media_id' => 'm3', 'segment_ref' => 'd1', 'position' => 0],
        ],
        allMedia: [fakeMedia('m1'), fakeMedia('m2'), fakeMedia('m3')],
    );

    expect(array_map(fn (PostMedia $m): string => $m->id, $out[0]))->toBe(['m1', 'm2']);
    expect(array_map(fn (PostMedia $m): string => $m->id, $out[1]))->toBe(['m3']);
});

test('media targeting a pruned authored segment with no surviving earlier segment defaults to section 0', function (): void {
    // Only authored segment 5 survives (as section 0); a placement on authored segment 3
    // ("d1") has no earlier authored segment (0, 1, 2) present at all, so the walk-back
    // exhausts and falls through to the hardcoded section-0 default.
    $out = app(SegmentMediaResolver::class)->resolve(
        sections: ['a5'], sectionSources: [5], segmentBreaks: ['b1', 'c1', 'd1'],
        placements: [
            ['post_media_id' => 'm1', 'segment_ref' => 'd1', 'position' => 0],
        ],
        allMedia: [fakeMedia('m1')],
    );

    expect(array_keys($out))->toBe([0]);
    expect(array_map(fn (PostMedia $m): string => $m->id, $out[0]))->toBe(['m1']);
});
