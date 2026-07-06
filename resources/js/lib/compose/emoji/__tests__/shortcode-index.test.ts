import { describe, expect, it } from 'vitest';

import {
    applySkinTone,
    buildEmojiIndex,
    rankEmoji,
} from '../shortcode-index';
import type { RawEmoji } from '../types';

const RAW: RawEmoji[] = [
    {
        hexcode: '1F604',
        emoji: '😄',
        label: 'grinning face with smiling eyes',
        tags: ['happy', 'smile'],
    },
    {
        hexcode: '1F44B',
        emoji: '👋',
        label: 'waving hand',
        tags: ['wave', 'hello'],
        skins: [
            { hexcode: '1F44B-1F3FB', emoji: '👋🏻', tone: 1 },
            { hexcode: '1F44B-1F3FF', emoji: '👋🏿', tone: 5 },
        ],
    },
    {
        hexcode: '1F60A',
        emoji: '☺️',
        label: 'smiling face',
        tags: ['smile'],
        skins: [
            // Couple/multi-person emoji with array tone (should be filtered)
            {
                hexcode: '1F9DD-couple',
                emoji: '🧑‍🤝‍🧑🏻',
                tone: [1, 1] as unknown as number,
            },
            // Normal single-tone skin (should be kept)
            { hexcode: '1F60A-1F3FB', emoji: '☺️🏻', tone: 1 },
        ],
    },
    {
        hexcode: '1F60F',
        emoji: '😏',
        label: 'smirking face',
        tags: ['playful'],
    },
];

const SHORTCODES: Record<string, string | string[]> = {
    '1F604': 'smile',
    '1F44B': ['wave', 'waving_hand'],
    '1F60A': 'smiling_face',
    '1F60F': 'smirk',
};

const index = buildEmojiIndex(RAW, SHORTCODES);

describe('buildEmojiIndex', () => {
    it('joins data and shortcodes by hexcode', () => {
        const smile = index.find((e) => e.hexcode === '1F604');
        expect(smile?.shortcodes).toEqual(['smile']);
        expect(smile?.tags).toEqual(['happy', 'smile']);
    });

    it('filters couple-emoji skins with array tones', () => {
        const smilingFace = index.find((e) => e.hexcode === '1F60A');
        // Should have exactly 1 skin (the normal tone), the array-tone one is filtered
        expect(smilingFace?.skins).toHaveLength(1);
        // The kept skin should be the single-number-tone one
        expect(smilingFace?.skins[0]).toEqual({ tone: 1, emoji: '☺️🏻' });
        // Verify the array-tone couple skin is absent
        expect(smilingFace?.skins.some((s) => s.emoji === '🧑‍🤝‍🧑🏻')).toBe(false);
    });
});

describe('rankEmoji', () => {
    it('ranks an exact shortcode above a substring match', () => {
        const results = rankEmoji(index, 'smile', { skinTone: 'none' });
        // Should match both 'smile' (exact) and 'smiling_face' (substring)
        expect(results.map((r) => r.emoji)).toContain('😄');
        expect(results.map((r) => r.emoji)).toContain('☺️');
        // Exact match must come first
        expect(results[0]?.emoji).toBe('😄');
        expect(results[1]?.emoji).toBe('☺️');
    });

    it('matches on shortcode prefix', () => {
        const results = rankEmoji(index, 'wav', { skinTone: 'none' });
        expect(results.map((r) => r.emoji)).toContain('👋');
    });

    it('matches on tag', () => {
        const results = rankEmoji(index, 'hello', { skinTone: 'none' });
        expect(results.map((r) => r.emoji)).toContain('👋');
    });

    it('returns nothing for an empty query', () => {
        expect(rankEmoji(index, '', { skinTone: 'none' })).toEqual([]);
    });

    it('respects the limit and returns higher-scored result', () => {
        // Query 'smile' matches both 'smile' (score 4) and 'smiling_face' (score 2)
        const allResults = rankEmoji(index, 'smile', { skinTone: 'none' });
        expect(allResults.length).toBeGreaterThanOrEqual(2);

        // With limit:1, only the highest-scored one should be returned
        const limitedResults = rankEmoji(index, 'smile', {
            skinTone: 'none',
            limit: 1,
        });
        expect(limitedResults).toHaveLength(1);
        // Should be the exact match ('smile'), not the substring match ('smiling_face')
        expect(limitedResults[0]?.emoji).toBe('😄');
    });

    it('applies the selected skin tone', () => {
        const results = rankEmoji(index, 'wave', { skinTone: 'dark' });
        expect(results.find((r) => r.label === 'waving hand')?.emoji).toBe('👋🏿');
    });
});

describe('applySkinTone', () => {
    it('returns the base emoji for none', () => {
        expect(applySkinTone(index[1]!, 'none')).toBe('👋');
    });

    it('returns the base emoji when the tone variant is missing', () => {
        expect(applySkinTone(index[0]!, 'dark')).toBe('😄');
    });
});
