import type { PlatformName } from '@/types/compose';

export type EngagementKey = 'likes' | 'comments' | 'reposts' | 'views';

export type EngagementItem = {
    key: EngagementKey;
    /** Platform-native noun for the count, e.g. "replies" on X vs "comments". */
    label: string;
    value: number;
};

/** The metric numbers a published target carries; a subset of PostStatTarget. */
export type EngagementSource = {
    likes: number;
    comments: number;
    reposts: number;
    impressions: number | null;
};

type Slot = { key: EngagementKey; label: string };

/**
 * Each network's action bar, in its own order and vocabulary, so the numbers
 * land where a reader of that platform expects them. `views` is appended from
 * `impressions` only where the platform exposes it and we actually captured it.
 *
 * Facebook, Instagram, and Threads have no engagement feature yet (Phase 1
 * plumbing only, engagement lands in Phases 3-5) so these layouts are
 * placeholders to keep this map exhaustive, and should be revisited when
 * their engagement connectors land.
 */
const LAYOUT: Record<PlatformName, Slot[]> = {
    x: [
        { key: 'comments', label: 'replies' },
        { key: 'reposts', label: 'reposts' },
        { key: 'likes', label: 'likes' },
        { key: 'views', label: 'views' },
    ],
    bluesky: [
        { key: 'comments', label: 'replies' },
        { key: 'reposts', label: 'reposts' },
        { key: 'likes', label: 'likes' },
    ],
    linkedin: [
        { key: 'likes', label: 'likes' },
        { key: 'comments', label: 'comments' },
        { key: 'reposts', label: 'reposts' },
        { key: 'views', label: 'impressions' },
    ],
    facebook: [
        { key: 'likes', label: 'likes' },
        { key: 'comments', label: 'comments' },
        { key: 'reposts', label: 'shares' },
    ],
    instagram: [
        { key: 'likes', label: 'likes' },
        { key: 'comments', label: 'comments' },
    ],
    threads: [
        { key: 'likes', label: 'likes' },
        { key: 'comments', label: 'replies' },
        { key: 'reposts', label: 'reposts' },
    ],
};

/**
 * Ordered engagement counts for a published target, shaped to mirror the real
 * platform's action bar. Returns an empty array for unknown platforms.
 */
export function engagementItems(
    platform: PlatformName,
    stat: EngagementSource,
): EngagementItem[] {
    const slots = LAYOUT[platform] ?? [];

    return slots
        .filter((slot) => slot.key !== 'views' || stat.impressions !== null)
        .map((slot) => ({
            key: slot.key,
            label: slot.label,
            value:
                slot.key === 'views' ? (stat.impressions ?? 0) : stat[slot.key],
        }));
}
