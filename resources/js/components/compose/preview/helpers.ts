import type { PlatformPreview } from '@/lib/compose/platform-preview';
import type { MediaView } from '@/types/compose';

/**
 * All media a preview will publish, in order. The builder places every visible
 * attachment on the first section (`items[0]`), so that is the single source of
 * truth for what the feed/carousel/collage renders.
 */
export function previewMedia(preview: PlatformPreview): MediaView[] {
    return preview.items[0]?.media ?? [];
}

/** First attachment — a Story publishes a single photo or video. */
export function storyMedia(preview: PlatformPreview): MediaView | null {
    return previewMedia(preview)[0] ?? null;
}

export function previewInitials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

export type CollageLayout = {
    /** Grid classes for the collage container (columns, rows, gap, aspect). */
    container: string;
    /** One entry per rendered tile; the value is its column/row span classes. */
    tiles: string[];
    /** Photos hidden behind the last tile's "+N" overlay (0 = none hidden). */
    overflow: number;
};

/**
 * Facebook's native album mosaic. The arrangement is driven purely by the photo
 * count — 2 split, 3 as one wide over two, 4 as a 2×2, and 5+ as two large tiles
 * over three with a "+N" overlay on the last visible tile — mirroring how the
 * Facebook feed lays out a multi-photo post.
 */
export function facebookCollage(count: number): CollageLayout {
    if (count <= 1) {
        return { container: 'grid grid-cols-1', tiles: [''], overflow: 0 };
    }

    if (count === 2) {
        return {
            container: 'grid aspect-[2/1] grid-cols-2 gap-0.5',
            tiles: ['', ''],
            overflow: 0,
        };
    }

    if (count === 3) {
        return {
            container: 'grid aspect-square grid-cols-2 grid-rows-2 gap-0.5',
            tiles: ['col-span-2', '', ''],
            overflow: 0,
        };
    }

    if (count === 4) {
        return {
            container: 'grid aspect-square grid-cols-2 grid-rows-2 gap-0.5',
            tiles: ['', '', '', ''],
            overflow: 0,
        };
    }

    return {
        container: 'grid aspect-[3/2] grid-cols-6 grid-rows-2 gap-0.5',
        tiles: [
            'col-span-3',
            'col-span-3',
            'col-span-2',
            'col-span-2',
            'col-span-2',
        ],
        overflow: count - 5,
    };
}

/**
 * Clamp a carousel index into `[0, length - 1]`. The Instagram web viewer stops
 * at the first and last slide (the arrows disappear) rather than wrapping, so the
 * index is bounded, never wrapped.
 */
export function clampIndex(index: number, length: number): number {
    if (length <= 0) {
        return 0;
    }

    return Math.max(0, Math.min(index, length - 1));
}
