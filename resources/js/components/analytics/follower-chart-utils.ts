import { dayjs } from '@/lib/datetime/dayjs';
import type { AnalyticsPageProps } from '@/types/metrics';

const ACCOUNT_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];

export type FollowerChartRow = Record<string, number | undefined> & {
    date: number;
};

type TooltipPayloadWithDate = readonly {
    payload?: {
        date?: Date | number | string | null;
    };
}[];

export function accountChartColor(index: number): string {
    return ACCOUNT_COLORS[index % ACCOUNT_COLORS.length];
}

/** Toggle membership of `accountId` in a hidden-id set. */
export function nextHiddenAccountIds(
    hidden: ReadonlySet<string>,
    accountId: string,
): Set<string> {
    const next = new Set(hidden);

    if (next.has(accountId)) {
        next.delete(accountId);
    } else {
        next.add(accountId);
    }

    return next;
}

export function formatFollowerTooltipDate(
    _label: unknown,
    payload?: TooltipPayloadWithDate,
): string {
    const date = payload?.[0]?.payload?.date;

    if (date === undefined || date === null || date === '') {
        return '';
    }

    const formattedDate = dayjs(date);

    return formattedDate.isValid() ? formattedDate.format('MMM D, YYYY') : '';
}

/**
 * Merge account series onto one row per calendar day so the tooltip can show
 * every account at the hovered time (capture timestamps rarely align exactly).
 */
export function buildFollowerChartData(
    accounts: AnalyticsPageProps['accounts'],
): FollowerChartRow[] {
    const dayKeys = [
        ...new Set(
            accounts.flatMap((account) =>
                account.series.map((point) =>
                    dayjs(point.at).format('YYYY-MM-DD'),
                ),
            ),
        ),
    ].sort();

    return dayKeys.map((day) => {
        const row: FollowerChartRow = {
            date: dayjs(day).startOf('day').valueOf(),
        };

        for (const account of accounts) {
            // Server already downsamples to one reading per day; take the last
            // of that day if multiple slipped through.
            const dayPoints = account.series.filter(
                (point) => dayjs(point.at).format('YYYY-MM-DD') === day,
            );
            const point = dayPoints.at(-1);

            if (point?.followers != null) {
                row[account.id] = point.followers;
            }
        }

        return row;
    });
}
