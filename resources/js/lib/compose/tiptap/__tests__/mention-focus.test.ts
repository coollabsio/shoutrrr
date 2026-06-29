import { describe, expect, it } from 'vitest';

import {
    endsMention,
    findMentionLabelEndInText,
} from '@/lib/compose/tiptap/mention-focus';

describe('findMentionLabelEndInText', () => {
    it('locates a boundary-delimited mention and returns the index past it', () => {
        expect(findMentionLabelEndInText('hi @john', '@john')).toBe(8);
        expect(findMentionLabelEndInText('@john here', '@john')).toBe(5);
    });

    it('treats trailing boundary punctuation as a valid mention end', () => {
        expect(findMentionLabelEndInText('@john!', '@john')).toBe(5);
        expect(findMentionLabelEndInText('hey @john, ok', '@john')).toBe(9);
    });

    it('ignores the label when it only appears inside a longer token', () => {
        expect(findMentionLabelEndInText('@sammy', '@sam')).toBeNull();
        expect(findMentionLabelEndInText('email@sam', '@sam')).toBeNull();
    });

    it('matches the real token even when a longer overlapping one follows', () => {
        // `@sam` must resolve to the standalone mention, not the `@sam` inside `@sammy`.
        expect(findMentionLabelEndInText('@sam and @sammy', '@sam')).toBe(4);
    });

    it('returns the last boundary-delimited occurrence', () => {
        expect(findMentionLabelEndInText('@a then @a', '@a')).toBe(10);
    });

    it('returns null for an absent label or empty needle', () => {
        expect(findMentionLabelEndInText('nothing here', '@john')).toBeNull();
        expect(findMentionLabelEndInText('@john', '')).toBeNull();
    });
});

describe('endsMention', () => {
    it('treats whitespace and handle punctuation as terminators', () => {
        for (const char of [' ', '\n', '.', ',', '!', '?', ';', ':']) {
            expect(endsMention(char)).toBe(true);
        }
    });

    it('does not treat word characters or the document end as terminators', () => {
        expect(endsMention('a')).toBe(false);
        expect(endsMention('@')).toBe(false);
        expect(endsMention('')).toBe(false);
    });
});
