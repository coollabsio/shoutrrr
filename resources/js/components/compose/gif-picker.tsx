import { useEffect, useRef, useState } from 'react';

import { GifTile } from '@/components/compose/gif-tile';
import { Loader2 } from '@/components/ui/icons';
import { useGifSearch } from '@/hooks/compose/use-gif-search';
import {
    FAVORITES_KEY,
    isFavorite,
    parseFavorites,
    toggleFavorite,
} from '@/lib/compose/gifs/favorites';
import { cn } from '@/lib/utils';
import { CATALOG_NOUNS, GIF_CATALOGS } from '@/types/gifs';
import type { GifCatalog, GifItem } from '@/types/gifs';

type Props = {
    onSelect: (item: GifItem) => void;
};

const CATALOG_LABELS: Record<GifCatalog, string> = {
    gif: 'GIFs',
    sticker: 'Stickers',
    clip: 'Clips',
};

/**
 * The GIF browser: catalog tabs, debounced search, a favourites shelf, and a
 * two-column masonry grid with infinite scroll. Mounted only while its popover
 * is open, so it never fetches in the background.
 */
export function GifPicker({ onSelect }: Props) {
    const [catalog, setCatalog] = useState<GifCatalog>('gif');
    const [favorites, setFavorites] = useState<GifItem[]>([]);
    const search = useGifSearch(catalog, true);
    const sentinel = useRef<HTMLDivElement | null>(null);
    const scrollArea = useRef<HTMLDivElement | null>(null);

    // A new query or catalog replaces the whole grid, so return to the top of
    // the fresh results rather than leaving the user stranded at the scroll
    // depth they'd reached in the previous ones.
    useEffect(() => {
        if (scrollArea.current !== null) {
            scrollArea.current.scrollTop = 0;
        }
    }, [catalog, search.query]);

    // `search` is a fresh object every render (see use-gif-search.ts), so
    // depending on it directly would tear down and recreate the observer on
    // every render. Stash the latest `loadMore` in a ref instead, and set up
    // the observer once — the callback always reads the current closure.
    //
    // Written from an effect (no dependency array, so it runs after every
    // commit) rather than during render: the observer callback only ever
    // fires after a commit anyway, so it always sees the value this effect
    // just wrote, and writing refs during render is a React anti-pattern
    // (breaks React Compiler memoization).
    const loadMoreRef = useRef(search.loadMore);
    useEffect(() => {
        loadMoreRef.current = search.loadMore;
    });

    // Whether the sentinel is currently intersecting, per the observer's most
    // recent report. Read by the settle-effect below to decide whether a
    // just-finished page should be followed by another.
    const intersectingRef = useRef(false);

    // How many items were on screen the last time we checked. Only used to
    // detect real progress (see the settle-effect below) — never to gate the
    // observer callback itself.
    const settledItemCountRef = useRef(0);

    useEffect(() => {
        setFavorites(parseFavorites(localStorage.getItem(FAVORITES_KEY)));
    }, []);

    useEffect(() => {
        const node = sentinel.current;

        if (!node) {
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            intersectingRef.current = entries[0]?.isIntersecting ?? false;

            if (intersectingRef.current) {
                loadMoreRef.current();
            }
        });
        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    // A short page (fewer results than it takes to overflow the 420px scroll
    // area) never produces a fresh intersection crossing — the sentinel was
    // already visible and stays visible, so the observer callback above never
    // fires again and pagination would otherwise silently stall even with
    // `hasNext: true`. Re-check once each page settles instead.
    //
    // Guarded against looping forever on a misbehaving API that reports
    // `has_next: true` alongside an empty page: only request another page
    // when the previous one actually added items, so a run of empty "next"
    // pages stops after the first one rather than hammering the endpoint.
    useEffect(() => {
        if (search.isLoading) {
            return;
        }

        // A catalog/query change resets `items` to a shorter (or empty)
        // array; resync the baseline to 0 instead of reading the shrink as
        // "no progress" for the rest of the new search. Resetting to 0 (not
        // to the new item count) makes the whole new first page count as
        // progress below — the commit where `items` actually reset to `[]`
        // was skipped by the `isLoading` guard above (still mid-flight for
        // the old catalog/query), so this is the first chance to resync.
        // `intersectingRef` still guards against a spurious fetch when the
        // new page does overflow the scroll area.
        if (search.items.length < settledItemCountRef.current) {
            settledItemCountRef.current = 0;
        }

        if (!intersectingRef.current || !search.hasNext) {
            settledItemCountRef.current = search.items.length;
            return;
        }

        if (search.items.length > settledItemCountRef.current) {
            settledItemCountRef.current = search.items.length;
            loadMoreRef.current();
        }
    }, [search.items, search.isLoading, search.hasNext]);

    function handleToggleFavorite(item: GifItem) {
        const next = toggleFavorite(favorites, item);
        setFavorites(next);

        // Safari private mode and quota-exceeded both throw here; the
        // in-memory toggle above must still take effect even when the
        // write-through to storage fails, so the picker doesn't crash
        // mid-interaction (the read path, `parseFavorites`, is already
        // defensive the same way).
        try {
            localStorage.setItem(FAVORITES_KEY, JSON.stringify(next));
        } catch {
            // Best-effort persistence only; nothing to recover here.
        }
    }

    // Every piece of copy follows the active tab, since that's the catalog the
    // search box actually queries.
    const noun = CATALOG_NOUNS[catalog];

    const showFavorites =
        search.query.trim() === '' &&
        favorites.filter((item) => item.catalog === catalog).length > 0;

    return (
        <div className="flex h-[420px] w-[420px] flex-col bg-popover text-popover-foreground">
            <div className="px-2 pt-2">
                <input
                    type="search"
                    value={search.query}
                    onChange={(event) => search.setQuery(event.target.value)}
                    aria-label={`Search ${noun}`}
                    placeholder={`Search ${noun}…`}
                    className="h-8 w-full appearance-none rounded-md bg-muted px-2.5 text-sm outline-none placeholder:text-muted-foreground"
                />
            </div>

            <div
                role="group"
                aria-label="Catalog"
                className="flex gap-1 px-2 pt-2"
            >
                {GIF_CATALOGS.map((entry) => (
                    <button
                        key={entry}
                        type="button"
                        aria-pressed={catalog === entry}
                        onClick={() => setCatalog(entry)}
                        className={cn(
                            'h-7 rounded-md px-2.5 text-xs font-medium text-muted-foreground transition-colors',
                            'hover:bg-muted hover:text-foreground',
                            catalog === entry && 'bg-muted text-foreground',
                        )}
                    >
                        {CATALOG_LABELS[entry]}
                    </button>
                ))}
            </div>

            <div
                ref={scrollArea}
                className="mt-2 flex-1 overflow-y-auto px-2 pb-2"
            >
                {showFavorites && (
                    <>
                        <div className="px-0.5 pb-1 text-xs font-medium text-muted-foreground">
                            Favourites
                        </div>
                        <div className="mb-3 columns-2 gap-2 [&>*]:mb-2">
                            {favorites
                                .filter((item) => item.catalog === catalog)
                                .map((item) => (
                                    <GifTile
                                        key={`fav-${item.slug}`}
                                        item={item}
                                        isFavorite
                                        onSelect={onSelect}
                                        onToggleFavorite={handleToggleFavorite}
                                    />
                                ))}
                        </div>
                    </>
                )}

                {search.error !== null ? (
                    <div className="flex h-full flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                        <span>{search.error}</span>
                        <button
                            type="button"
                            onClick={search.retry}
                            className="rounded-md bg-muted px-2.5 py-1 text-xs font-medium text-foreground"
                        >
                            Try again
                        </button>
                    </div>
                ) : search.items.length === 0 && !search.isLoading ? (
                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                        No {noun} found.
                    </div>
                ) : (
                    <div className="columns-2 gap-2 [&>*]:mb-2">
                        {search.items.map((item) => (
                            <GifTile
                                key={`${item.catalog}-${item.slug}`}
                                item={item}
                                isFavorite={isFavorite(favorites, item)}
                                onSelect={onSelect}
                                onToggleFavorite={handleToggleFavorite}
                            />
                        ))}
                    </div>
                )}

                {search.isLoading && search.items.length === 0 && (
                    <div className="columns-2 gap-2 [&>*]:mb-2">
                        {Array.from({ length: 6 }, (_, index) => (
                            <div
                                key={index}
                                className="h-24 animate-pulse rounded-lg bg-muted"
                            />
                        ))}
                    </div>
                )}

                {/* Next-page spinner: only while appending to an existing grid — the
                    first page renders the skeleton above instead. */}
                {search.isLoading && search.items.length > 0 && (
                    <div
                        role="status"
                        aria-label={`Loading more ${noun}`}
                        className="flex items-center justify-center py-3"
                    >
                        <Loader2
                            className="size-4 animate-spin text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                )}

                <div ref={sentinel} className="h-px" />
            </div>
        </div>
    );
}

export default GifPicker;
