import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    composePickedAt,
    defaultPickedAt,
    partsInTz,
} from '../pick-time-popover';

describe('pick-time tz helpers', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('defaults to the next clock hour in the scheduling timezone', () => {
        vi.setSystemTime(new Date('2026-06-22T08:23:00Z'));

        expect(defaultPickedAt('UTC')).toBe('2026-06-22T09:00:00Z');
    });

    it('composePickedAt interprets wall-clock in the given tz', () => {
        // 09:00 in Asia/Kolkata (UTC+5:30) === 03:30 UTC
        expect(composePickedAt('2026-06-20', 9, 0, 'Asia/Kolkata')).toBe(
            '2026-06-20T03:30:00Z',
        );
        // 09:00 UTC stays 09:00 UTC
        expect(composePickedAt('2026-06-20', 9, 0, 'UTC')).toBe(
            '2026-06-20T09:00:00Z',
        );
    });

    it('partsInTz round-trips composePickedAt', () => {
        const iso = composePickedAt('2026-06-20', 14, 30, 'Asia/Kolkata');
        expect(partsInTz(iso, 'Asia/Kolkata')).toEqual({
            dayIso: '2026-06-20',
            hour: 14,
            minute: 30,
        });
    });
});
