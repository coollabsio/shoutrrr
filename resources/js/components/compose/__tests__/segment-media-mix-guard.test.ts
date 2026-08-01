import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

// `composer.tsx` wires a lot of imperative, timing-sensitive glue (file
// pickers, editor sessions, refs) that isn't practical to mount and drive in
// isolation — see segment-media-target.test.ts for the same source-assertion
// approach. These pin the fix for a bug where dropping a video into one
// thread segment was rejected as "mixed video and images" because an image
// already lived in a *different* segment: the guard checked `state.media`
// (the whole post's flat media across every segment) instead of only the
// media already attached to the segment the drop targets.
const source = () =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/components/compose/composer.tsx'),
        'utf8',
    );

/** Strip all whitespace so assertions don't hinge on line-wrapping. */
function collapse(text: string): string {
    return text.replace(/\s+/g, '');
}

describe('the mix-and-GIF attach guards are scoped to the target segment', () => {
    it('checks wouldMixVideoAndImages against the target segment only, not the whole post', () => {
        const composer = collapse(source());

        expect(composer).not.toContain(
            'wouldMixVideoAndImages(state.media,all',
        );
        expect(composer).toContain(
            'wouldMixVideoAndImages(mediaForSegment(resolveTargetSegmentRef()),all',
        );
    });

    it('checks wouldViolateBlueskyGif against the target segment only, not the whole post', () => {
        const composer = collapse(source());

        expect(composer).not.toContain(
            'wouldViolateBlueskyGif(state.media,all',
        );
        expect(composer).toContain(
            'wouldViolateBlueskyGif(mediaForSegment(resolveTargetSegmentRef()),all',
        );
    });
});
