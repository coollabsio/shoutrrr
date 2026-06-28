import { describe, expect, it } from 'vitest';

import { defaultSettings, normalizeSettings } from '../settings';

describe('defaultSettings', () => {
    it('spans the full clip with no crop', () => {
        expect(defaultSettings(12.5)).toEqual({
            version: 1,
            trim: { start: 0, end: 12.5 },
            crop: null,
            aspect: 'auto',
        });
    });

    it('clamps a non-finite duration to 0', () => {
        expect(defaultSettings(Number.NaN).trim).toEqual({ start: 0, end: 0 });
    });
});

describe('normalizeSettings', () => {
    it('falls back to defaults for non-object input', () => {
        expect(normalizeSettings(null, 8)).toEqual(defaultSettings(8));
    });

    it('clamps trim within [0, duration] and enforces start < end', () => {
        const result = normalizeSettings(
            {
                version: 1,
                trim: { start: -3, end: 999 },
                crop: null,
                aspect: 'auto',
            },
            10,
        );
        expect(result.trim).toEqual({ start: 0, end: 10 });
    });

    it('swaps reversed trim points', () => {
        const result = normalizeSettings(
            {
                version: 1,
                trim: { start: 7, end: 2 },
                crop: null,
                aspect: '1:1',
            },
            10,
        );
        expect(result.trim).toEqual({ start: 2, end: 7 });
        expect(result.aspect).toBe('1:1');
    });

    it('drops an invalid aspect to auto', () => {
        const result = normalizeSettings(
            {
                version: 1,
                trim: { start: 0, end: 5 },
                crop: null,
                aspect: 'banana',
            },
            5,
        );
        expect(result.aspect).toBe('auto');
    });
});
