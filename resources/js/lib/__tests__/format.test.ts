import { describe, expect, it } from 'vitest';

import { formatCompact, formatFull } from '../format';

describe('formatCompact', () => {
    it('leaves small counts intact', () => {
        expect(formatCompact(0)).toBe('0');
        expect(formatCompact(820)).toBe('820');
    });

    it('abbreviates thousands and millions', () => {
        expect(formatCompact(1200)).toBe('1.2K');
        expect(formatCompact(12_400)).toBe('12.4K');
        expect(formatCompact(1_300_000)).toBe('1.3M');
    });
});

describe('formatFull', () => {
    it('groups digits for exact display', () => {
        expect(formatFull(12_432)).toBe('12,432');
    });
});
