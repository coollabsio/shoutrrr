import { describe, expect, it } from 'vitest';

import { computeExportScale } from '../export';

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
