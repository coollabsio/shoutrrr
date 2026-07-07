import { describe, expect, it } from 'vitest';

import { parseRecents, parseSkinTone, pushRecent } from '../preferences';

describe('pushRecent', () => {
    it('prepends a new emoji', () => {
        expect(pushRecent(['😀'], '🔥')).toEqual(['🔥', '😀']);
    });

    it('moves an existing emoji to the front (dedupe)', () => {
        expect(pushRecent(['😀', '🔥', '❤️'], '❤️')).toEqual([
            '❤️',
            '😀',
            '🔥',
        ]);
    });

    it('caps the list length', () => {
        const list = Array.from({ length: 16 }, (_, i) => String(i));
        expect(pushRecent(list, 'new', 16)).toHaveLength(16);
        expect(pushRecent(list, 'new', 16)[0]).toBe('new');
    });
});

describe('parseRecents', () => {
    it('parses a valid array', () => {
        expect(parseRecents('["😀","🔥"]')).toEqual(['😀', '🔥']);
    });

    it('falls back to [] on null', () => {
        expect(parseRecents(null)).toEqual([]);
    });

    it('falls back to [] on corrupt JSON', () => {
        expect(parseRecents('{not json')).toEqual([]);
    });

    it('falls back to [] on a non-string array', () => {
        expect(parseRecents('[1,2,3]')).toEqual([]);
    });
});

describe('parseSkinTone', () => {
    it('accepts a valid tone', () => {
        expect(parseSkinTone('dark')).toBe('dark');
    });

    it('falls back to none on garbage', () => {
        expect(parseSkinTone('purple')).toBe('none');
        expect(parseSkinTone(null)).toBe('none');
    });
});
