import { describe, expect, it } from 'vitest';

import { deriveQueueStatus, formatSlotLabel } from '../use-next-slot';

describe('deriveQueueStatus', () => {
    it('reports no-schedule when has_schedule is false', () => {
        expect(
            deriveQueueStatus({
                has_schedule: false,
                slot: null,
                timezone: 'UTC',
            }),
        ).toBe('no-schedule');
    });

    it('reports full when a schedule exists but no slot is free', () => {
        expect(
            deriveQueueStatus({
                has_schedule: true,
                slot: null,
                timezone: 'UTC',
            }),
        ).toBe('full');
    });

    it('reports found when a slot is returned', () => {
        expect(
            deriveQueueStatus({
                has_schedule: true,
                slot: '2026-06-16T07:00:00+00:00',
                timezone: 'UTC',
            }),
        ).toBe('found');
    });
});

describe('formatSlotLabel', () => {
    it('renders the slot as wall-clock in the given tz', () => {
        // 07:00 UTC === 09:00 in UTC+2 (Europe/Berlin, summer). 2026-06-16 is a Tuesday.
        expect(
            formatSlotLabel('2026-06-16T07:00:00+00:00', 'Europe/Berlin'),
        ).toBe('Tue, Jun 16 · 9:00 AM');
    });
});
