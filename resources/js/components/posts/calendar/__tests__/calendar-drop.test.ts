import { describe, expect, it } from 'vitest';

import {
    computeMonthDrop,
    shouldOpenMonthDay,
} from '@/components/posts/calendar/month-grid';
import { computeWeekDrop } from '@/components/posts/calendar/week-grid';

describe('calendar day activation', () => {
    it('does not open a month day in the past', () => {
        expect(shouldOpenMonthDay(true)).toBe(false);
    });

    it('opens future or current month days regardless of existing posts', () => {
        expect(shouldOpenMonthDay(false)).toBe(true);
    });
});

describe('calendar drop math', () => {
    it('month drop keeps the time-of-day, swaps the date', () => {
        const out = computeMonthDrop(
            '2026-06-15T09:30:00Z',
            '2026-06-20',
            'UTC',
        );
        expect(out).toBe('2026-06-20T09:30:00Z');
    });

    it('week drop snaps to day @ hour:00 by default', () => {
        const out = computeWeekDrop('2026-06-20', 14, 'UTC');
        expect(out).toBe('2026-06-20T14:00:00Z');
    });

    it('week drop applies a sub-hour minute offset', () => {
        expect(computeWeekDrop('2026-06-20', 14, 'UTC', 30)).toBe(
            '2026-06-20T14:30:00Z',
        );
        expect(computeWeekDrop('2026-06-20', 9, 'UTC', 45)).toBe(
            '2026-06-20T09:45:00Z',
        );
    });
});
