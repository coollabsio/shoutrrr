import { describe, expect, it } from 'vitest';

import {
    postsByDay,
    windowDays,
} from '@/components/posts/calendar/agenda-list';
import type { PostRowData } from '@/components/posts/post-row';
import { dayjs } from '@/lib/datetime/dayjs';

function post(overrides: Partial<PostRowData>): PostRowData {
    return {
        id: 'p1',
        base_text: '',
        status: 'scheduled',
        status_label: 'Scheduled',
        author: null,
        target_count: 1,
        updated_at: '2026-06-01T00:00:00Z',
        scheduled_at: null,
        published_at: null,
        platforms: [],
        targets: [],
        media_count: 0,
        media_preview: null,
        ...overrides,
    };
}

describe('windowDays', () => {
    it('month view spans every day of the anchor month', () => {
        const days = windowDays(
            dayjs.tz('2026-06-15', 'YYYY-MM-DD', 'UTC'),
            'month',
        );
        expect(days).toHaveLength(30);
        expect(days[0].format('YYYY-MM-DD')).toBe('2026-06-01');
        expect(days[29].format('YYYY-MM-DD')).toBe('2026-06-30');
    });

    it('week view spans the 7-day Sunday-first week around the anchor', () => {
        const days = windowDays(
            dayjs.tz('2026-06-17', 'YYYY-MM-DD', 'UTC'),
            'week',
        );
        expect(days).toHaveLength(7);
        expect(days[0].format('YYYY-MM-DD')).toBe('2026-06-14'); // Sunday
        expect(days[6].format('YYYY-MM-DD')).toBe('2026-06-20'); // Saturday
    });
});

describe('postsByDay', () => {
    it('buckets posts by their local scheduled/published day', () => {
        const a = post({ id: 'a', scheduled_at: '2026-06-20T09:00:00Z' });
        const b = post({ id: 'b', scheduled_at: '2026-06-20T18:30:00Z' });
        const c = post({ id: 'c', published_at: '2026-06-21T12:00:00Z' });

        const byDay = postsByDay([a, b, c], 'UTC');

        expect(byDay.get('2026-06-20')?.map((p) => p.id)).toEqual(['a', 'b']);
        expect(byDay.get('2026-06-21')?.map((p) => p.id)).toEqual(['c']);
    });

    it('uses the timezone to place a post on the right calendar day', () => {
        // 02:00Z on the 21st is still the 20th in New York (UTC-4 in June).
        const p = post({ id: 'tz', scheduled_at: '2026-06-21T02:00:00Z' });

        expect(
            postsByDay([p], 'America/New_York').get('2026-06-20'),
        ).toHaveLength(1);
        expect(postsByDay([p], 'UTC').get('2026-06-21')).toHaveLength(1);
    });

    it('skips posts with no scheduled or published time', () => {
        const byDay = postsByDay([post({ id: 'draft' })], 'UTC');
        expect(byDay.size).toBe(0);
    });
});
