import { describe, expect, it } from 'vitest';

import { anchorRefsFromBreaks } from '@/lib/compose/tiptap/segment-media-anchors';

describe('anchorRefsFromBreaks', () => {
    it('prefixes __head__ and keeps break ids in order', () => {
        expect(anchorRefsFromBreaks(['bk_1', 'bk_2'])).toEqual([
            '__head__',
            'bk_1',
            'bk_2',
        ]);
    });

    it('falls back to positional b<index> for id-less breaks, matching docToSegmentsWithBreaks', () => {
        expect(anchorRefsFromBreaks([null, 'bk_2', null])).toEqual([
            '__head__',
            'b0',
            'bk_2',
            'b2',
        ]);
    });

    it('returns just the head ref when there are no breaks', () => {
        expect(anchorRefsFromBreaks([])).toEqual(['__head__']);
    });
});
