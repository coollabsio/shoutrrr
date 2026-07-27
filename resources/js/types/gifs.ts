/** Kept in sync with `KlipyClient::CATALOGS` on the backend. */
export const GIF_CATALOGS = ['gif', 'sticker', 'clip'] as const;

export type GifCatalog = (typeof GIF_CATALOGS)[number];

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
