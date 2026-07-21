import { describe, expect, it } from 'vitest';

import {
    followerYDomain,
    formatFollowerTooltipDate,
} from '../follower-chart-utils';

describe('follower chart tooltip', () => {
    it('formats the x-axis date from the tooltip payload', () => {
        const timestamp = new Date('2026-06-22T12:00:00.000Z').getTime();

        expect(
            formatFollowerTooltipDate('Andras Bacsai', [
                { payload: { date: timestamp } },
            ]),
        ).toBe('Jun 22, 2026');
    });
});

describe('followerYDomain', () => {
    it('pads a growing series so small changes fill the panel', () => {
        // 1600 → 1700 must not collapse to a flat line against a 0 baseline.
        const [min, max] = followerYDomain([1600, 1650, 1700]);
        expect(min).toBeGreaterThan(1500);
        expect(min).toBeLessThan(1600);
        expect(max).toBeGreaterThan(1700);
        // The visible band is a small window around the data, not [0, 1700].
        expect(max - min).toBeLessThan(200);
    });

    it('never anchors the window at zero for a high-count account', () => {
        const [min] = followerYDomain([1600, 1700]);
        expect(min).toBeGreaterThan(0);
    });

    it('gives a flat series a small symmetric band', () => {
        const [min, max] = followerYDomain([50, 50, 50]);
        expect(min).toBeLessThan(50);
        expect(max).toBeGreaterThan(50);
    });

    it('handles a series that starts at zero', () => {
        const [min, max] = followerYDomain([0, 10, 25]);
        expect(min).toBeLessThanOrEqual(0);
        expect(max).toBeGreaterThan(25);
    });

    it('falls back to a unit window when there are no values', () => {
        expect(followerYDomain([])).toEqual([0, 1]);
    });
});
