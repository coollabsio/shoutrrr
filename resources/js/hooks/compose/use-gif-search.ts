import { useEffect, useRef, useState } from 'react';

import gifs from '@/routes/gifs';
import type { GifCatalog, GifItem, GifPage } from '@/types/gifs';

const DEBOUNCE_MS = 300;

export type UseGifSearch = {
    query: string;
    setQuery: (value: string) => void;
    items: GifItem[];
    isLoading: boolean;
    error: string | null;
    hasNext: boolean;
    loadMore: () => void;
    retry: () => void;
};

/**
 * The three values that must always change together: a page number is only
 * meaningful paired with the catalog/query it was fetched for. Kept as one
 * state object so a query (or catalog) change and the page-1 reset it
 * requires can never be observed separately by the fetch effect below.
 */
type ActiveRequest = {
    catalog: GifCatalog;
    query: string;
    page: number;
};

/**
 * Owns one catalog's browse state: debounced query, accumulated pages, and the
 * loading/error flags the picker renders. Fetching is inert while `enabled` is
 * false, so a closed popover costs nothing.
 */
export function useGifSearch(
    catalog: GifCatalog,
    enabled: boolean,
): UseGifSearch {
    const [query, setQuery] = useState('');
    const [request, setRequest] = useState<ActiveRequest>({
        catalog,
        query: '',
        page: 1,
    });
    const [items, setItems] = useState<GifItem[]>([]);
    const [hasNext, setHasNext] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);

    // Ignore a resolved response whose request has been superseded.
    const requestId = useRef(0);

    // `catalog` is a prop, not state. Resync it into `request` synchronously
    // during render (not a second effect) so the fetch effect below never
    // fires with a page left over from the previous catalog paired with the
    // new one.
    const [syncedCatalog, setSyncedCatalog] = useState(catalog);
    if (catalog !== syncedCatalog) {
        setSyncedCatalog(catalog);
        setRequest((current) => ({ ...current, catalog, page: 1 }));
        setItems([]);
    }

    // Debounce the raw query into `request` as a single atomic update: the
    // query and its page-1 reset land in one setState call, so they can
    // never be split across two commits the way separate `debounced`/`page`
    // states were.
    useEffect(() => {
        const handle = window.setTimeout(() => {
            setRequest((current) => ({ ...current, query, page: 1 }));
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(handle);
    }, [query]);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const id = ++requestId.current;
        const controller = new AbortController();
        setIsLoading(true);
        setError(null);

        const trimmed = request.query.trim();
        const url = gifs.browse.url(
            { catalog: request.catalog },
            {
                query: {
                    page: request.page,
                    q: trimmed === '' ? undefined : trimmed,
                },
            },
        );

        fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<GifPage>;
            })
            .then((data) => {
                if (id !== requestId.current) {
                    return;
                }
                setItems((current) =>
                    request.page === 1
                        ? data.items
                        : [...current, ...data.items],
                );
                setHasNext(data.has_next);
            })
            .catch(() => {
                // An aborted request (cleanup below) rejects too; it's not a
                // real failure, so it must never surface as an error banner.
                if (controller.signal.aborted) {
                    return;
                }
                if (id === requestId.current) {
                    setError("GIFs aren't loading right now.");
                }
            })
            .finally(() => {
                if (controller.signal.aborted) {
                    return;
                }
                if (id === requestId.current) {
                    setIsLoading(false);
                }
            });

        return () => {
            controller.abort();
        };
    }, [request, enabled, attempt]);

    return {
        query,
        setQuery,
        items,
        isLoading,
        error,
        hasNext,
        loadMore: () => {
            if (!isLoading && hasNext) {
                setRequest((current) => ({
                    ...current,
                    page: current.page + 1,
                }));
            }
        },
        retry: () => setAttempt((current) => current + 1),
    };
}
