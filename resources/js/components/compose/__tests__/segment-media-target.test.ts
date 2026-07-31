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
