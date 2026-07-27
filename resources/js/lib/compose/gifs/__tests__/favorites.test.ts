import { describe, expect, it } from 'vitest';

import type { GifItem } from '@/types/gifs';

import {
    isFavorite,
    MAX_FAVORITES,
    parseFavorites,
    toggleFavorite,
} from '../favorites';

function item(slug: string): GifItem {
    return {
        id: slug,
        slug,
        catalog: 'gif',
        title: slug,
        preview: {
            url: `https://cdn.klipy.com/${slug}.gif`,
            mime: 'image/gif',
            width: 120,
            height: 90,
            bytes: 1000,
        },
        variants: [],
    };
}

describe('toggleFavorite', () => {
    it('adds a new favourite to the front', () => {
        expect(
            toggleFavorite([item('a')], item('b')).map((i) => i.slug),
        ).toEqual(['b', 'a']);
    });

    it('removes an existing favourite', () => {
        expect(
            toggleFavorite([item('a'), item('b')], item('a')).map(
                (i) => i.slug,
            ),
        ).toEqual(['b']);
    });

    it('dedupes by slug across catalogs', () => {
        const sticker = { ...item('a'), catalog: 'sticker' as const };
        expect(toggleFavorite([item('a')], sticker)).toHaveLength(2);
    });

    it('caps the list', () => {
        const list = Array.from({ length: MAX_FAVORITES }, (_, i) =>
            item(String(i)),
        );
        expect(toggleFavorite(list, item('new'))).toHaveLength(MAX_FAVORITES);
        expect(toggleFavorite(list, item('new'))[0].slug).toBe('new');
    });
});

describe('isFavorite', () => {
    it('matches on catalog and slug', () => {
        expect(isFavorite([item('a')], item('a'))).toBe(true);
        expect(isFavorite([item('a')], item('b'))).toBe(false);
        expect(isFavorite([item('a')], { ...item('a'), catalog: 'clip' })).toBe(
            false,
        );
    });
});

describe('parseFavorites', () => {
    it('parses a valid list', () => {
        expect(parseFavorites(JSON.stringify([item('a')]))).toHaveLength(1);
    });

    it('falls back to [] on null', () => {
        expect(parseFavorites(null)).toEqual([]);
    });

    it('falls back to [] on corrupt json', () => {
        expect(parseFavorites('{not json')).toEqual([]);
    });

    it('drops entries missing required fields', () => {
        expect(parseFavorites('[{"slug":"a"},null,3]')).toEqual([]);
    });
});
