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
 * Owns one catalog's browse state: debounced query, accumulated pages, and the
 * loading/error flags the picker renders. Fetching is inert while `enabled` is
 * false, so a closed popover costs nothing.
 */
export function useGifSearch(
    catalog: GifCatalog,
    enabled: boolean,
): UseGifSearch {
    const [query, setQuery] = useState('');
    const [debounced, setDebounced] = useState('');
    const [items, setItems] = useState<GifItem[]>([]);
    const [page, setPage] = useState(1);
    const [hasNext, setHasNext] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);

    // Ignore a resolved response whose request has been superseded.
    const requestId = useRef(0);

    useEffect(() => {
        const handle = window.setTimeout(
            () => setDebounced(query),
            DEBOUNCE_MS,
        );

        return () => window.clearTimeout(handle);
    }, [query]);

    // A new query (or catalog) starts a fresh result set at page 1.
    useEffect(() => {
        setPage(1);
        setItems([]);
    }, [debounced, catalog]);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const id = ++requestId.current;
        setIsLoading(true);
        setError(null);

        const trimmed = debounced.trim();
        const url = gifs.browse.url(
            { catalog },
            { query: { page, q: trimmed === '' ? undefined : trimmed } },
        );

        fetch(url, { headers: { Accept: 'application/json' } })
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
                    page === 1 ? data.items : [...current, ...data.items],
                );
                setHasNext(data.has_next);
            })
            .catch(() => {
                if (id === requestId.current) {
                    setError("GIFs aren't loading right now.");
                }
            })
            .finally(() => {
                if (id === requestId.current) {
                    setIsLoading(false);
                }
            });
    }, [catalog, debounced, page, enabled, attempt]);

    return {
        query,
        setQuery,
        items,
        isLoading,
        error,
        hasNext,
        loadMore: () => {
            if (!isLoading && hasNext) {
                setPage((current) => current + 1);
            }
        },
        retry: () => setAttempt((current) => current + 1),
    };
}
