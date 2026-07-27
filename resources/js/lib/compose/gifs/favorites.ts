import type { GifCatalog, GifItem } from '@/types/gifs';
import { GIF_CATALOGS } from '@/types/gifs';

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

/**
 * A minimally renderable variant: an object with a usable `url` and `mime`.
 * Width/height/bytes are not required for a stored favourite to be kept —
 * they're display metadata, not something a consumer destructures blindly.
 */
function isPlausibleVariant(
    value: unknown,
): value is { url: string; mime: string } {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const candidate = value as { url?: unknown; mime?: unknown };

    return (
        typeof candidate.url === 'string' && typeof candidate.mime === 'string'
    );
}

function isValidCatalog(value: unknown): value is GifCatalog {
    return (
        typeof value === 'string' &&
        (GIF_CATALOGS as readonly string[]).includes(value)
    );
}

/**
 * Parse a stored list, tolerating null/corrupt/wrong-shaped values. A
 * partially-valid entry (e.g. one bad variant among many) is dropped
 * entirely rather than salvaged, since consumers render the whole item.
 */
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
                typeof candidate.id === 'string' &&
                typeof candidate.slug === 'string' &&
                typeof candidate.title === 'string' &&
                isValidCatalog(candidate.catalog) &&
                isPlausibleVariant(candidate.preview) &&
                Array.isArray(candidate.variants) &&
                candidate.variants.every(isPlausibleVariant)
            );
        });
    } catch {
        return [];
    }
}
