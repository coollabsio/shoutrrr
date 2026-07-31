import { describe, expect, it } from 'vitest';

import {
    docToSegments,
    docToSegmentsWithBreaks,
    segmentRefs,
    segmentsToDoc,
    segmentsToDocWithBreaks,
} from '../tiptap-doc';

describe('docToSegments / segmentsToDoc', () => {
    it('serializes section-break nodes to segment boundaries', () => {
        const doc = {
            type: 'doc',
            content: [
                { type: 'paragraph', content: [{ type: 'text', text: 'one' }] },
                { type: 'sectionBreak' },
                { type: 'paragraph', content: [{ type: 'text', text: 'two' }] },
            ],
        };

        expect(docToSegments(doc)).toEqual(['one', 'two']);
    });

    it('keeps paragraph newlines inside a segment', () => {
        const doc = {
            type: 'doc',
            content: [
                { type: 'paragraph', content: [{ type: 'text', text: 'a' }] },
                { type: 'paragraph', content: [{ type: 'text', text: 'b' }] },
            ],
        };

        expect(docToSegments(doc)).toEqual(['a\nb']);
    });

    it('treats a literal --- as ordinary paragraph text', () => {
        const doc = {
            type: 'doc',
            content: [
                { type: 'paragraph', content: [{ type: 'text', text: '---' }] },
            ],
        };

        expect(docToSegments(doc)).toEqual(['---']);
    });

    it('round-trips segments through a doc and back', () => {
        const segments = ['first\nline', 'second'];

        expect(docToSegments(segmentsToDoc(segments))).toEqual(segments);
    });

    it('preserves an empty paragraph (blank line) within a segment on round-trip', () => {
        expect(docToSegments(segmentsToDoc(['a\n\nb']))).toEqual(['a\n\nb']);
    });

    it('empty segments produce a single empty paragraph doc', () => {
        expect(segmentsToDoc([''])).toEqual({
            type: 'doc',
            content: [{ type: 'paragraph' }],
        });
    });
});

describe('break-aware serialization', () => {
    it('extracts segments and their opening break ids', () => {
        const doc = {
            type: 'doc',
            content: [
                { type: 'paragraph', content: [{ type: 'text', text: 'a' }] },
                { type: 'sectionBreak', attrs: { breakId: 'b1' } },
                { type: 'paragraph', content: [{ type: 'text', text: 'b' }] },
            ],
        };
        const { segments, breakIds } = docToSegmentsWithBreaks(doc);
        expect(segments).toEqual(['a', 'b']);
        expect(breakIds).toEqual(['b1']);
        expect(segmentRefs(breakIds)).toEqual(['__head__', 'b1']);
    });

    it('round-trips through segmentsToDocWithBreaks', () => {
        const doc = segmentsToDocWithBreaks(['a', 'b'], ['b1']);
        expect(docToSegmentsWithBreaks(doc)).toEqual({
            segments: ['a', 'b'],
            breakIds: ['b1'],
        });
    });
});
