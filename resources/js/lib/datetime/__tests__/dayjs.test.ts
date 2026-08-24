import { describe, expect, it } from 'vitest';

import {
    dayFlags,
    monthRange,
    parseYm,
    weekRange,
    ymKey,
} from '@/lib/datetime/dayjs';

describe('datetime helpers', () => {
    it('ymKey formats a YYYY-MM key', () => {
        expect(ymKey(parseYm('2026-06')!)).toBe('2026-06');
    });

    it('parseYm rejects garbage', () => {
        expect(parseYm('not-a-month')).toBeNull();
    });

    it('monthRange returns a 42-day Sunday-first grid', () => {
        const { days } = monthRange(parseYm('2026-06')!);
        expect(days).toHaveLength(42);
        expect(days[0].day()).toBe(0); // Sunday
        // June 2026 starts on a Monday, so the grid's first cell is May 31.
        expect(days[0].format('YYYY-MM-DD')).toBe('2026-05-31');
    });

    it('weekRange returns 7 Sunday-first days', () => {
        const { days } = weekRange(parseYm('2026-06')!);
        expect(days).toHaveLength(7);
        expect(days[0].day()).toBe(0);
    });

    describe('dayFlags (issue #168 — today highlight is one day early)', () => {
        // Cells come from the UTC anchor (the real code path via parseYm), while
        // `todayKey` is the local calendar day in the scheduling tz. Comparing by
        // date key must flag the correct day regardless of the anchor's offset.
        const cells = monthRange(parseYm('2026-08')!).days;

        it('flags exactly the local today, not the prior UTC day', () => {
            const today = cells.filter(
                (d) => dayFlags(d, '2026-08-21').isToday,
            );
            expect(today.map((d) => d.format('YYYY-MM-DD'))).toEqual([
                '2026-08-21',
            ]);
        });

        it('dims every day up to but not including today', () => {
            const past = cells.filter((d) => dayFlags(d, '2026-08-21').isPast);
            expect(past.at(-1)?.format('YYYY-MM-DD')).toBe('2026-08-20');
            expect(dayFlags(cells[0], '2026-08-21').isPast).toBe(true);
        });

        it('marks future days as neither today nor past', () => {
            const { isToday, isPast } = dayFlags(
                monthRange(parseYm('2026-08')!).days.find(
                    (d) => d.format('YYYY-MM-DD') === '2026-08-22',
                )!,
                '2026-08-21',
            );
            expect(isToday).toBe(false);
            expect(isPast).toBe(false);
        });
    });
});
