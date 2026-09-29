import { afterEach, describe, expect, it, vi } from 'vitest';

import { dayjs } from '@/lib/datetime/dayjs';

import { postLiveStatus } from '../live-status';

afterEach(() => vi.useRealTimers());

describe('postLiveStatus', () => {
    it('shows the exact date/time plus a countdown for a scheduled post', () => {
        // Freeze "now" so fromNow() is deterministic, and use a non-UTC tz so
        // the assertion proves the scheduled instant is converted (14:30 UTC →
        // 07:30 PDT), not just echoed.
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-02T14:30:00Z'));

        const scheduled_at = '2026-09-05T14:30:00Z';
        const label = postLiveStatus(
            {
                status: 'scheduled',
                scheduled_at,
                published_at: null,
            },
            'America/Los_Angeles',
        );
        expect(label).toBe('Going live Sep 5, 2026 at 7:30 AM · in 3 days');
    });

    it('reports how long ago a post was published', () => {
        const published_at = dayjs().subtract(2, 'hour').toISOString();
        const label = postLiveStatus({
            status: 'published',
            scheduled_at: null,
            published_at,
        });
        expect(label).toMatch(/^Published .* ago$/);
    });

    it('surfaces when a scheduled run was missed', () => {
        const scheduled_at = dayjs().subtract(1, 'hour').toISOString();
        const label = postLiveStatus({
            status: 'missed',
            scheduled_at,
            published_at: null,
        });
        expect(label).toMatch(/^Missed · was due .* ago$/);
    });

    it('has no live line for a draft', () => {
        expect(
            postLiveStatus({
                status: 'draft',
                scheduled_at: null,
                published_at: null,
            }),
        ).toBeNull();
    });

    it('falls back to a plain label when a scheduled time is missing', () => {
        expect(
            postLiveStatus({
                status: 'scheduled',
                scheduled_at: null,
                published_at: null,
            }),
        ).toBe('Scheduled');
    });
});
