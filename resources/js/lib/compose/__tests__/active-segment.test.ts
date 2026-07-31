import { expect, it } from 'vitest';

import { activeSegmentRef } from '@/lib/compose/active-segment';

it('returns HEAD when the caret is before any break', () => {
    const doc = {
        type: 'doc',
        content: [
            { type: 'paragraph', content: [{ type: 'text', text: 'hello' }] },
            { type: 'sectionBreak', attrs: { breakId: 'b1' } },
            { type: 'paragraph', content: [{ type: 'text', text: 'world' }] },
        ],
    };
    expect(activeSegmentRef(doc, 2, ['b1'])).toBe('__head__'); // caret in "hello"
});

it('returns the break id when the caret is after a break', () => {
    const doc = {
        type: 'doc',
        content: [
            { type: 'paragraph', content: [{ type: 'text', text: 'hello' }] },
            { type: 'sectionBreak', attrs: { breakId: 'b1' } },
            { type: 'paragraph', content: [{ type: 'text', text: 'world' }] },
        ],
    };
    expect(activeSegmentRef(doc, 9, ['b1'])).toBe('b1'); // caret in "world"
});

it('does not undercount an empty paragraph as a size-1 leaf (regression)', () => {
    // An empty paragraph (blank first segment) serializes with NO `content`
    // key at all — same as a leaf/atom node — but it still occupies 2 PM
    // positions (open + close tokens), not 1.
    const doc = {
        type: 'doc',
        content: [
            { type: 'paragraph' },
            { type: 'sectionBreak', attrs: { breakId: 'b1' } },
            { type: 'paragraph', content: [{ type: 'text', text: 'world' }] },
        ],
    };
    expect(activeSegmentRef(doc, 1, ['b1'])).toBe('__head__'); // caret in the empty paragraph
    expect(activeSegmentRef(doc, 4, ['b1'])).toBe('b1'); // caret in "world"
});
