import type { GifItem } from '@/types/gifs';

export const MAX_FAVORITES = 50;
export const FAVORITES_KEY = 'shoutrrr:gifs:favorites';

/** Favourites are keyed on catalog + slug — the same slug can exist per catalog. */
function keyOf(item: Pick<GifItem, 'catalog' | 'slug'>): string {
    return `${item.catalog}:${item.slug}`;
}

export function isFavorite(list: GifItem[], item: GifItem): boolean {
    return list.some((entry) => keyOf(entry) === keyOf(item));
}

/** Add to the front, or remove when already present. Capped at MAX_FAVORITES. */
export function toggleFavorite(list: GifItem[], item: GifItem): GifItem[] {
    if (isFavorite(list, item)) {
        return list.filter((entry) => keyOf(entry) !== keyOf(item));
    }

    return [item, ...list].slice(0, MAX_FAVORITES);
}

/** Parse a stored list, tolerating null/corrupt/wrong-shaped values. */
export function parseFavorites(raw: string | null): GifItem[] {
    if (!raw) {
        return [];
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.filter((entry): entry is GifItem => {
            if (typeof entry !== 'object' || entry === null) {
                return false;
            }
            const candidate = entry as Partial<GifItem>;

            return (
                typeof candidate.slug === 'string' &&
                typeof candidate.catalog === 'string' &&
                typeof candidate.preview === 'object' &&
                candidate.preview !== null &&
                typeof candidate.preview.url === 'string' &&
                Array.isArray(candidate.variants)
            );
        });
    } catch {
        return [];
    }
}
