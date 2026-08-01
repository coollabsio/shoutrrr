import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

// `composer.tsx` wires a lot of imperative, timing-sensitive glue (file
// pickers, editor sessions, refs) that isn't practical to mount and drive in
// isolation — the existing composer-preview-controls test in this directory
// takes the same source-assertion approach for the same reason. These pin the
// fix for a bug where an image/video picked via a specific thread's "add
// media" button would silently land on the wrong (default/head) thread: the
// explicit segment target was released as soon as the file picker's promise
// settled, which is well before the beautifier/trim editor session (and its
// eventual `addMedia` dispatch) actually finishes.
const source = () =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/components/compose/composer.tsx'),
        'utf8',
    );

/** Collapse runs of whitespace so assertions don't hinge on exact formatting. */
function normalize(text: string): string {
    return text.replace(/\s+/g, ' ');
}

describe('per-segment media targeting survives the editor session', () => {
    it('threads the resolved target segment into both editor completion dispatches', () => {
        const composer = source();

        // Both the image editor's "apply" and the video editor's "complete"
        // callback must tag their addMedia dispatch with a segment, not rely
        // on the reducer's bare __head__ default.
        const imageOnAddMedia = composer.slice(
            composer.indexOf('const imageEditor = useImageEditor({'),
            composer.indexOf('const videoEditor = useVideoEditor({'),
        );
        expect(imageOnAddMedia).toContain(
            'segmentRef: resolveTargetSegmentRef()',
        );

        const videoOnComplete = composer.slice(
            composer.indexOf('const videoEditor = useVideoEditor({'),
            composer.indexOf('// The toolbar'),
        );
        expect(videoOnComplete).toContain(
            'segmentRef: resolveTargetSegmentRef()',
        );
    });

    it('does not release the explicit segment target until the editing session actually ends', () => {
        const composer = source();

        const acceptSegmentFiles = composer.slice(
            composer.indexOf('function acceptSegmentFiles'),
            composer.indexOf('function closeVideoEditing'),
        );
        // The picker's own promise settling must NOT unconditionally null out
        // the held target — that's the root cause of the bug (the beautifier/
        // trim editor session, and the addMedia dispatch it produces, both
        // happen well after this promise resolves).
        expect(
            normalize(acceptSegmentFiles).includes(
                normalize(
                    `handleAddedFiles(files).finally(() => { explicitUploadSegmentRef.current = null;`,
                ),
            ),
        ).toBe(false);
        expect(acceptSegmentFiles).toContain('openedEditorRef.current');

        const endEditingStep = composer.slice(
            composer.indexOf('function endEditingStep'),
            composer.indexOf('const openedEditorRef'),
        );
        expect(endEditingStep).toContain(
            'explicitUploadSegmentRef.current = null;',
        );

        const closeVideoEditing = composer.slice(
            composer.indexOf('function closeVideoEditing'),
            composer.indexOf('function openVideo'),
        );
        expect(closeVideoEditing).toContain(
            'explicitUploadSegmentRef.current = null;',
        );
    });

    it('re-opening an attached video or image pins its editor session to its own segment, not the caret', () => {
        const composer = source();

        // Re-editing an already-placed video/image mints a NEW media id on
        // apply (video trim, and first-time image beautify) and drops the
        // old one — if the explicit target isn't set to the media's CURRENT
        // segment before the editor opens, `resolveTargetSegmentRef()` falls
        // through to the caret and the media visibly jumps to whatever
        // segment the user was last typing in.
        const openVideo = composer.slice(
            composer.indexOf('function openVideo'),
            composer.indexOf('function openImage'),
        );
        expect(openVideo).toContain(
            'explicitUploadSegmentRef.current = segmentRefForMedia(mediaId);',
        );
        expect(
            openVideo.indexOf('explicitUploadSegmentRef.current ='),
        ).toBeLessThan(openVideo.indexOf('setEditing({'));

        const openImage = composer.slice(
            composer.indexOf('function openImage'),
            composer.indexOf('async function applyEditing'),
        );
        expect(openImage).toContain(
            'explicitUploadSegmentRef.current = segmentRefForMedia(mediaId);',
        );
        // Set once, ahead of BOTH branches (`reedit` and `raw`) — the media's
        // current segment is known before the code decides which editing
        // kind to open.
        expect(
            openImage.indexOf('explicitUploadSegmentRef.current ='),
        ).toBeLessThan(openImage.indexOf("kind: 'reedit'"));
        expect(
            openImage.indexOf('explicitUploadSegmentRef.current ='),
        ).toBeLessThan(openImage.indexOf("kind: 'raw'"));

        // The `batch` (fresh multi-upload) and already-in-flight `reedit`
        // apply path are untouched: `reedit` still preserves the media id via
        // `applyEdit` rather than minting a new one via the segment-scoped
        // `addMedia` dispatch.
        const applyEditing = composer.slice(
            composer.indexOf('async function applyEditing'),
            composer.indexOf('function cancelEditing'),
        );
        expect(applyEditing).toContain('imageEditor.applyEdit(');

        // `segmentRefForMedia` resolves against the placements the rest of
        // the composer already reads from (`activeScopePlacements`), and
        // falls back to the head segment defensively rather than leaving the
        // target unset (which would silently reintroduce the caret bug).
        const segmentRefForMedia = composer.slice(
            composer.indexOf('function segmentRefForMedia'),
            composer.indexOf('function openVideo'),
        );
        expect(segmentRefForMedia).toContain('activeScopePlacements');
        expect(segmentRefForMedia).toContain("?? '__head__'");
    });

    it('hides the per-segment add-media row when an override outgrows the canonical segment breaks', () => {
        const composer = source();
        const normalized = normalize(composer);

        expect(normalized).toContain('const overrideExceedsSegmentBreaks =');
        expect(normalized).toContain(
            normalize(
                `state.overrideByAccount[activeAccount.id]?.length ?? 0) > state.segmentBreaks.length + 1`,
            ),
        );
        expect(normalized).toContain(
            normalize(`(singleThread || overrideExceedsSegmentBreaks)`),
        );
    });
});
