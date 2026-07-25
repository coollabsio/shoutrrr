import { describe, expect, it } from 'vitest';

import { computeExportScale, pickComposedFormat } from '../export';
import { gradientToFill, GRADIENTS, NO_BACKGROUND } from '../gradients';
import { defaultSettings } from '../settings';

describe('computeExportScale', () => {
    it('uses the base scale when the result stays under the cap', () => {
        expect(computeExportScale(500, 2048, 2)).toBe(2);
    });

    it('caps the longest edge for large images', () => {
        expect(computeExportScale(2000, 2048, 2)).toBeCloseTo(2048 / 2000);
    });

    it('never returns more than the base scale', () => {
        expect(computeExportScale(10, 2048, 2)).toBe(2);
    });
});

describe('pickComposedFormat', () => {
    it('encodes JPEG when a background makes the composite opaque', () => {
        const settings = {
            ...defaultSettings(),
            background: gradientToFill(GRADIENTS[0]),
        };
        expect(pickComposedFormat(settings).type).toBe('image/jpeg');
    });

    it('encodes WebP (alpha-preserving) when there is no background', () => {
        const settings = { ...defaultSettings(), background: NO_BACKGROUND };
        expect(pickComposedFormat(settings).type).toBe('image/webp');
    });

    it('starts from a high, non-lossless quality', () => {
        const { quality } = pickComposedFormat(defaultSettings());
        expect(quality).toBeGreaterThan(0.8);
        expect(quality).toBeLessThanOrEqual(1);
    });
});
