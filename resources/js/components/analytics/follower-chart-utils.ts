import { dayjs } from '@/lib/datetime/dayjs';

// Distinct categorical hues (not a warm monochrome ramp) so each account line
// stays tellable-apart. Assigned in fixed order and validated colorblind-safe.
const ACCOUNT_COLORS = [
    'var(--account-1)',
    'var(--account-2)',
    'var(--account-3)',
    'var(--account-4)',
    'var(--account-5)',
    'var(--account-6)',
    'var(--account-7)',
    'var(--account-8)',
];

type TooltipPayloadWithDate = readonly {
    payload?: {
        date?: Date | number | string | null;
    };
}[];

export function accountChartColor(index: number): string {
    return ACCOUNT_COLORS[index % ACCOUNT_COLORS.length];
}

/**
 * A padded [min, max] window around a follower series so the trend fills the
 * panel rather than being crushed against a zero baseline. Each account is
 * plotted on its own axis (one chart per account), so a +100 change on 1,600
 * and a +25 change from 0 both read as real movement instead of a flat line.
 */
export function followerYDomain(values: number[]): [number, number] {
    if (values.length === 0) {
        return [0, 1];
    }

    const min = Math.min(...values);
    const max = Math.max(...values);

    if (min === max) {
        // Flat series — sit the line mid-panel with a small symmetric band.
        const pad = Math.max(Math.abs(min) * 0.05, 1);
        return [min - pad, max + pad];
    }

    // Headroom above and below so peaks and troughs don't touch the edges.
    const pad = (max - min) * 0.15;
    return [min - pad, max + pad];
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
