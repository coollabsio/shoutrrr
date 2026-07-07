import { describe, expect, it } from 'vitest';

import { matchEmojiTrigger } from '../trigger';

describe('matchEmojiTrigger', () => {
    it('matches a colon token after whitespace', () => {
        // "hello :sm" — caret at doc pos 20, ":sm" is 3 chars → from = 17
        expect(matchEmojiTrigger('hello :sm', 20)).toEqual({
            query: 'sm',
            from: 17,
        });
    });

    it('matches a colon token at the start of a block', () => {
        expect(matchEmojiTrigger(':smile', 6)).toEqual({
            query: 'smile',
            from: 0,
        });
    });

    it('ignores a colon glued to a word (URLs)', () => {
        expect(matchEmojiTrigger('http://', 7)).toBeNull();
        expect(matchEmojiTrigger('see http://x', 12)).toBeNull();
    });

    it('ignores times like 12:30', () => {
        expect(matchEmojiTrigger('12:30', 5)).toBeNull();
    });

    it('ignores text emoticons like :)', () => {
        expect(matchEmojiTrigger('hey :)', 6)).toBeNull();
    });

    it('requires at least two query characters', () => {
        expect(matchEmojiTrigger('hey :a', 6)).toBeNull();
    });

    it('returns null with no colon', () => {
        expect(matchEmojiTrigger('just typing', 11)).toBeNull();
    });
});
