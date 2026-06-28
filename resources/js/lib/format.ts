const compactFormatter = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/**
 * Short, glanceable count the way social platforms show it: 820, 1.2K, 12.4K,
 * 1.3M. Pair with {@link formatFull} for an exact `title`/`aria-label`.
 */
export function formatCompact(value: number): string {
    return compactFormatter.format(value);
}

/** Exact, grouped count (e.g. 12,432) for tooltips and assistive text. */
export function formatFull(value: number): string {
    return value.toLocaleString();
}
