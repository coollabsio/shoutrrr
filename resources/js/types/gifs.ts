/** Kept in sync with `KlipyClient::CATALOGS` on the backend. */
export const GIF_CATALOGS = ['gif', 'sticker', 'clip'] as const;

export type GifCatalog = (typeof GIF_CATALOGS)[number];

/**
 * What to call each catalog's items in user-facing copy — tab labels, the
 * search placeholder, empty and error states — so every surface follows the
 * tab the browser is actually searching.
 */
export const CATALOG_NOUNS: Record<GifCatalog, string> = {
    gif: 'GIFs',
    sticker: 'stickers',
    clip: 'clips',
};

/** One downloadable representation of an item; `bytes` is null when unreported. */
export type GifVariant = {
    url: string;
    mime: string;
    width: number;
    height: number;
    bytes: number | null;
};

/** A browse result, already normalized server-side — no vendor fields. */
export type GifItem = {
    id: string;
    slug: string;
    catalog: GifCatalog;
    title: string;
    preview: GifVariant;
    variants: GifVariant[];
};

export type GifPage = {
    items: GifItem[];
    has_next: boolean;
};
