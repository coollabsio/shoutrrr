import { describe, expect, it } from 'vitest';

import {
    copyMondayToWeekdays,
    formatTime,
    mergeSlots,
    normalizeSlots,
    PRESETS,
    type Slot,
    timesForDay,
} from '../queue-schedule';

describe('normalizeSlots', () => {
    it('dedupes and sorts by weekday, hour, then minute', () => {
        const input: Slot[] = [
            { weekday: 1, hour: 9, minute: 45 },
            { weekday: 1, hour: 9, minute: 15 },
            { weekday: 1, hour: 9, minute: 15 },
            { weekday: 0, hour: 8, minute: 0 },
        ];
        expect(normalizeSlots(input)).toEqual([
            { weekday: 0, hour: 8, minute: 0 },
            { weekday: 1, hour: 9, minute: 15 },
            { weekday: 1, hour: 9, minute: 45 },
        ]);
    });
});

describe('PRESETS', () => {
    it('"Weekdays 9am" is Mon–Fri at 09:00 with minute 0', () => {
        const preset = PRESETS.find((p) => p.label === 'Weekdays 9am');
        expect(preset?.slots).toEqual([
            { weekday: 1, hour: 9, minute: 0 },
            { weekday: 2, hour: 9, minute: 0 },
            { weekday: 3, hour: 9, minute: 0 },
            { weekday: 4, hour: 9, minute: 0 },
            { weekday: 5, hour: 9, minute: 0 },
        ]);
    });

    it('merging a preset twice does not duplicate slots', () => {
        const preset = PRESETS.find((p) => p.label === 'Weekdays 9am')!;
        const twice = mergeSlots(mergeSlots([], preset.slots), preset.slots);
        expect(twice).toHaveLength(5);
    });
});

describe('timesForDay', () => {
    it('returns ascending {hour,minute} for a weekday', () => {
        const slots: Slot[] = [
            { weekday: 1, hour: 9, minute: 45 },
            { weekday: 1, hour: 9, minute: 15 },
            { weekday: 1, hour: 8, minute: 0 },
            { weekday: 2, hour: 7, minute: 0 },
        ];
        expect(timesForDay(slots, 1)).toEqual([
            { hour: 8, minute: 0 },
            { hour: 9, minute: 15 },
            { hour: 9, minute: 45 },
        ]);
    });
});

describe('copyMondayToWeekdays', () => {
    it("copies Monday's minute-precise times to Tue–Fri, keeping existing", () => {
        const current: Slot[] = [
            { weekday: 1, hour: 9, minute: 30 },
            { weekday: 6, hour: 12, minute: 0 },
        ];
        const result = copyMondayToWeekdays(current);
        expect(result).toHaveLength(6); // Mon–Fri @ 9:30 (5) + Sat 12:00 (1)
        expect(timesForDay(result, 3)).toEqual([{ hour: 9, minute: 30 }]);
        expect(timesForDay(result, 6)).toEqual([{ hour: 12, minute: 0 }]);
    });
});

describe('formatTime', () => {
    it('formats 12-hour times with padded minutes', () => {
        expect(formatTime(9, 0)).toBe('9:00 AM');
        expect(formatTime(9, 37)).toBe('9:37 AM');
        expect(formatTime(0, 5)).toBe('12:05 AM');
        expect(formatTime(12, 0)).toBe('12:00 PM');
        expect(formatTime(17, 30)).toBe('5:30 PM');
    });
});
