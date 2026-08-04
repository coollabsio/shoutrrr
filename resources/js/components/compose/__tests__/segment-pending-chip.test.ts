import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

// `composer.tsx` wires a lot of imperative, timing-sensitive glue (file
// pickers, editor sessions, refs) that isn't practical to mount and drive in
// isolation — see segment-media-target.test.ts for the same source-assertion
// approach. No test in this repo mounts composer.tsx itself (unlike the
// presentational SegmentMediaRow, which does have a real render test in this
// directory): the filtering this file pins happens inline inside
// composer.tsx's `renderSegmentMedia` callback, computing the `pending` prop
// *before* SegmentMediaRow ever mounts, so rendering SegmentMediaRow alone
// can't exercise it. This pins the fix for a bug where a video's "uploading"
// chip showed up under the wrong thread segment: the row decided which
// segment should show `mediaUploads.pending` by comparing the *current*
// value of `explicitUploadSegmentRef.current ?? activeSegRef` against its
// own ref — but `explicitUploadSegmentRef` is released as soon as the
// upload starts (see segment-media-target.test.ts), so for the whole (often
// multi-second) video upload the chip rendered under whatever segment the
// caret currently sat in instead of the segment the upload actually
// targets. Each pending upload now carries its own `segmentRef`, captured
// when it began, so the row can just filter on it directly.
const source = () =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/components/compose/composer.tsx'),
        'utf8',
    );

/** Strip all whitespace so assertions don't hinge on line-wrapping. */
function collapse(text: string): string {
    return text.replace(/\s+/g, '');
}

describe('the per-segment pending upload chip is scoped by the chip itself, not a live ref', () => {
    it('filters mediaUploads.pending by each item’s own segmentRef', () => {
        const composer = collapse(source());

        expect(composer).not.toContain(
            '(explicitUploadSegmentRef.current??activeSegRef)===ref?mediaUploads.pending:[]',
        );
        expect(composer).toContain(
            'mediaUploads.pending.filter((p)=>p.segmentRef===ref',
        );
    });
});
