export type GifCatalog = 'gif' | 'sticker' | 'clip';

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
