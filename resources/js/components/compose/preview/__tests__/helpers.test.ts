import { describe, expect, it } from 'vitest';

import { clampIndex, facebookCollage } from '../helpers';

describe('facebookCollage', () => {
    it('renders a single photo full width with no overflow', () => {
        const layout = facebookCollage(1);

        expect(layout.tiles).toHaveLength(1);
        expect(layout.overflow).toBe(0);
    });

    it('splits two photos into two columns', () => {
        const layout = facebookCollage(2);

        expect(layout.tiles).toHaveLength(2);
        expect(layout.container).toContain('grid-cols-2');
        expect(layout.overflow).toBe(0);
    });

    it('lays three photos out as one wide tile over two', () => {
        const layout = facebookCollage(3);

        expect(layout.tiles).toEqual(['col-span-2', '', '']);
        expect(layout.overflow).toBe(0);
    });

    it('lays four photos out as a 2x2 grid', () => {
        const layout = facebookCollage(4);

        expect(layout.tiles).toHaveLength(4);
        expect(layout.container).toContain('grid-rows-2');
        expect(layout.overflow).toBe(0);
    });

    it('caps at five visible tiles and counts the rest as overflow', () => {
        expect(facebookCollage(5).overflow).toBe(0);
        expect(facebookCollage(6).overflow).toBe(1);

        const many = facebookCollage(9);
        expect(many.tiles).toHaveLength(5);
        expect(many.overflow).toBe(4);
    });
});

describe('clampIndex', () => {
    it('bounds the index within the slide range without wrapping', () => {
        expect(clampIndex(-3, 4)).toBe(0);
        expect(clampIndex(2, 4)).toBe(2);
        expect(clampIndex(9, 4)).toBe(3);
    });

    it('returns 0 for an empty carousel', () => {
        expect(clampIndex(0, 0)).toBe(0);
        expect(clampIndex(5, 0)).toBe(0);
    });
});
