import type { PlatformName } from '@/types/compose';

/**
 * The longest run of consecutive newlines each platform preserves when it
 * *renders* a post. Longer runs are collapsed to this many, so a single blank
 * line (`\n\n`) is the most vertical spacing X and LinkedIn will show, while
 * Bluesky renders text verbatim.
 *
 * Behaviour confirmed 2026: X collapses runs of line breaks down to a single
 * blank line; LinkedIn's feed keeps at most one blank line between paragraphs;
 * Bluesky stores and renders standard newlines untouched. Tune a value here if
 * a platform changes how it collapses spacing.
 */
const MAX_CONSECUTIVE_NEWLINES: Record<PlatformName, number> = {
    x: 2,
    linkedin: 2,
    bluesky: Number.POSITIVE_INFINITY,
};

/**
 * Collapse runs of newlines in `text` down to the most the given platform will
 * actually render, so the composer preview shows the post's spacing as it will
 * appear once published. Text is returned unchanged for platforms that preserve
 * newlines verbatim (i.e. an infinite allowance).
 */
export function collapsePlatformNewlines(
    text: string,
    platform: PlatformName,
): string {
    const max = MAX_CONSECUTIVE_NEWLINES[platform];
    if (!Number.isFinite(max)) {
        return text;
    }

    const collapsibleRun = new RegExp(`\\n{${max + 1},}`, 'g');

    return text.replace(collapsibleRun, '\n'.repeat(max));
}
