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
