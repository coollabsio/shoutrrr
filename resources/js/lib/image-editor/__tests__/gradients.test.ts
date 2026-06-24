import { describe, expect, it } from 'vitest';

import {
    backgroundCss,
    findGradient,
    GRADIENTS,
    gradientToFill,
} from '../gradients';

describe('gradient presets', () => {
    it('ships at least 8 presets with unique ids', () => {
        const ids = GRADIENTS.map((g) => g.id);
        expect(GRADIENTS.length).toBeGreaterThanOrEqual(8);
        expect(new Set(ids).size).toBe(ids.length);
    });

    it('every preset has >= 2 stops and a valid angle', () => {
        for (const g of GRADIENTS) {
            expect(g.stops.length).toBeGreaterThanOrEqual(2);
            expect(g.angle).toBeGreaterThanOrEqual(0);
            expect(g.angle).toBeLessThanOrEqual(360);
            for (const s of g.stops) {
                expect(s.at).toBeGreaterThanOrEqual(0);
                expect(s.at).toBeLessThanOrEqual(1);
            }
        }
    });

    it('findGradient resolves a known id and rejects an unknown one', () => {
        expect(findGradient(GRADIENTS[0].id)?.id).toBe(GRADIENTS[0].id);
        expect(findGradient('nope')).toBeUndefined();
    });

    it('backgroundCss renders a linear-gradient with angle + stops', () => {
        const css = backgroundCss(gradientToFill(GRADIENTS[0]));
        expect(css).toContain('linear-gradient(');
        expect(css).toContain('deg');
        expect(css).toContain('%');
    });
});
