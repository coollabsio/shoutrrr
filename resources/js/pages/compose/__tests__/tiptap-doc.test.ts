import { describe, expect, it } from 'vitest';

import { docToBaseText, baseTextToDoc } from '../tiptap-doc';

describe('docToBaseText', () => {
    it('joins paragraphs with newlines', () => {
        const doc = {
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'line one' }],
                },
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'line two' }],
                },
            ],
        };
        expect(docToBaseText(doc)).toBe('line one\nline two');
    });

    it('renders a section break as a --- break line', () => {
        const doc = {
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'first post' }],
                },
                { type: 'sectionBreak' },
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'second post' }],
                },
            ],
        };
        expect(docToBaseText(doc)).toBe('first post\n---\nsecond post');
    });

    it('treats an empty paragraph as a blank line', () => {
        const doc = { type: 'doc', content: [{ type: 'paragraph' }] };
        expect(docToBaseText(doc)).toBe('');
    });
});

describe('baseTextToDoc round-trips', () => {
    it('rebuilds paragraphs and breaks from text', () => {
        const text = 'first post\n---\nsecond post';
        const doc = baseTextToDoc(text);
        expect(docToBaseText(doc)).toBe(text);
    });
});
