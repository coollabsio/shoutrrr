import { describe, expect, it } from 'vitest';

import {
    cellId,
    countDiff,
    hourLabel,
    setsEqual,
    setToSlots,
    slotsToSet,
} from '../posting-schedule-grid';

describe('posting-schedule-grid helpers', () => {
    it('maps weekday/hour to a Sun-first cell id', () => {
        expect(cellId(0, 0)).toBe(0); // Sunday midnight
        expect(cellId(1, 9)).toBe(33); // Monday 09:00
        expect(cellId(6, 23)).toBe(167); // Saturday 23:00
    });

    it('round-trips slots through a set', () => {
        const slots = [
            { weekday: 1, hour: 9 },
            { weekday: 3, hour: 17 },
        ];
        const set = slotsToSet(slots);
        expect(set.has(cellId(1, 9))).toBe(true);
        expect(set.has(cellId(3, 17))).toBe(true);

        const back = setToSlots(set);
        expect(back).toEqual([
            { weekday: 1, hour: 9, position: 0 },
            { weekday: 3, hour: 17, position: 1 },
        ]);
    });

    it('orders setToSlots by cell id and assigns positions', () => {
        const set = new Set([cellId(3, 17), cellId(1, 9)]);
        const slots = setToSlots(set);
        expect(slots.map((s) => s.weekday)).toEqual([1, 3]);
        expect(slots.map((s) => s.position)).toEqual([0, 1]);
    });

    it('computes added/removed diffs', () => {
        const initial = new Set([1, 2, 3]);
        const next = new Set([2, 3, 4, 5]);
        expect(countDiff(next, initial)).toBe(2); // added: 4,5
        expect(countDiff(initial, next)).toBe(1); // removed: 1
        expect(setsEqual(initial, next)).toBe(false);
        expect(setsEqual(new Set([1, 2, 3]), initial)).toBe(true);
    });

    it('formats hour labels like the old grid', () => {
        expect(hourLabel(0)).toBe('12a');
        expect(hourLabel(9)).toBe('9a');
        expect(hourLabel(12)).toBe('12p');
        expect(hourLabel(15)).toBe('3p');
    });
});
