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
];

const SHORTCODES: Record<string, string | string[]> = {
    '1F604': 'smile',
    '1F44B': ['wave', 'waving_hand'],
};

const index = buildEmojiIndex(RAW, SHORTCODES);

describe('buildEmojiIndex', () => {
    it('joins data and shortcodes by hexcode', () => {
        const smile = index.find((e) => e.hexcode === '1F604');
        expect(smile?.shortcodes).toEqual(['smile']);
        expect(smile?.tags).toEqual(['happy', 'smile']);
    });
});

describe('rankEmoji', () => {
    it('ranks an exact shortcode above a substring match', () => {
        const results = rankEmoji(index, 'smile', { skinTone: 'none' });
        expect(results[0]?.emoji).toBe('😄');
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

    it('respects the limit', () => {
        expect(rankEmoji(index, 'a', { skinTone: 'none', limit: 1 }).length).toBeLessThanOrEqual(1);
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
