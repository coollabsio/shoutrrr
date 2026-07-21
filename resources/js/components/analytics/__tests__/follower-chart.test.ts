import { describe, expect, it } from 'vitest';

import {
    buildFollowerChartData,
    formatFollowerTooltipDate,
    nextHiddenAccountIds,
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

describe('buildFollowerChartData', () => {
    it('merges same-day readings from every account onto one row', () => {
        const rows = buildFollowerChartData([
            {
                id: 'x',
                platform: 'x',
                handle: '@acme',
                display_name: 'Acme X',
                avatar_url: null,
                status: 'ok',
                latest_followers: 120,
                series: [
                    {
                        at: '2026-05-14T09:15:00.000Z',
                        followers: 100,
                        following: 10,
                    },
                    {
                        at: '2026-05-15T09:15:00.000Z',
                        followers: 120,
                        following: 10,
                    },
                ],
            },
            {
                id: 'li',
                platform: 'linkedin',
                handle: 'acme-inc',
                display_name: 'Acme Inc',
                avatar_url: null,
                status: 'ok',
                latest_followers: 9800,
                series: [
                    {
                        at: '2026-05-14T11:15:00.000Z',
                        followers: 9700,
                        following: 20,
                    },
                    {
                        at: '2026-05-15T11:15:00.000Z',
                        followers: 9800,
                        following: 20,
                    },
                ],
            },
        ]);

        expect(rows).toHaveLength(2);
        expect(rows[0]).toMatchObject({
            x: 100,
            li: 9700,
        });
        expect(rows[1]).toMatchObject({
            x: 120,
            li: 9800,
        });
        expect(Object.keys(rows[0]).filter((k) => k !== 'date')).toEqual(
            expect.arrayContaining(['x', 'li']),
        );
    });
});

describe('nextHiddenAccountIds', () => {
    it('hides a visible account and shows a hidden one', () => {
        const hidden = nextHiddenAccountIds(new Set(), 'x');
        expect([...hidden]).toEqual(['x']);

        const shown = nextHiddenAccountIds(hidden, 'x');
        expect([...shown]).toEqual([]);
    });
});
