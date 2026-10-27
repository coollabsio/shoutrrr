import { describe, expect, it } from 'vitest';

import { fitImageForUpload, fitWithinPixels } from '../fit-image';

describe('fitWithinPixels', () => {
    it('keeps dimensions that already fit the ceiling', () => {
        expect(fitWithinPixels(4000, 3000, 16_000_000)).toEqual({
            width: 4000,
            height: 3000,
        });
    });

    it('scales a 48 MP photo under the ceiling and keeps its aspect ratio', () => {
        const { width, height } = fitWithinPixels(8000, 6000, 16_000_000);

        expect(width * height).toBeLessThanOrEqual(16_000_000);
        expect(width).toBe(4618);
        expect(height).toBe(3464);
    });
});

describe('fitImageForUpload', () => {
    it('returns a GIF untouched so its animation is kept', async () => {
        const gif = new File(['gif'], 'anim.gif', { type: 'image/gif' });

        expect(await fitImageForUpload(gif, 1)).toBe(gif);
    });
});
