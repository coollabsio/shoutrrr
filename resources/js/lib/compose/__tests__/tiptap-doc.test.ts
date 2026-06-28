import { describe, expect, it } from 'vitest';

import { docToSegments, segmentsToDoc } from '../tiptap-doc';

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
