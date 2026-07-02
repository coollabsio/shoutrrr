import { useEffect, useRef, useState } from 'react';

type Suggestion = {
    did: string;
    handle: string;
    displayName?: string;
    avatar?: string;
};

export function useBlueskyHandleResolver() {
    const [avatar, setAvatar] = useState('');
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [suggestionsOpen, setSuggestionsOpen] = useState(false);
    const [selectedIdx, setSelectedIdx] = useState(-1);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | undefined>(
        undefined,
    );
    const reqIdRef = useRef(0);

    function onInput(value: string) {
        setAvatar('');
        setSelectedIdx(-1);
        clearTimeout(debounceRef.current);

        const q = value.replace(/^@/, '').trim();

        if (q.length >= 3 && !q.includes('.')) {
            const id = ++reqIdRef.current;
            debounceRef.current = setTimeout(
                () => fetchSuggestions(q, id),
                300,
            );
        } else if (q.includes('.') && q.length >= 4) {
            const id = ++reqIdRef.current;
            setSuggestions([]);
            setSuggestionsOpen(false);
            debounceRef.current = setTimeout(() => resolveProfile(q, id), 400);
        } else {
            setSuggestions([]);
            setSuggestionsOpen(false);
        }
    }

    async function fetchSuggestions(q: string, id: number) {
        try {
            const res = await fetch(
                `https://public.api.bsky.app/xrpc/app.bsky.actor.searchActorsTypeahead?q=${encodeURIComponent(q)}&limit=6`,
            );
            if (id !== reqIdRef.current) return;
            if (res.ok) {
                const d = await res.json();
                const actors: Suggestion[] = (d.actors ?? []).map(
                    (a: Suggestion) => ({
                        did: a.did,
                        handle: a.handle,
                        displayName: a.displayName,
                        avatar: a.avatar,
                    }),
                );
                setSuggestions(actors);
                setSuggestionsOpen(actors.length > 0);
            }
        } catch {
            // ignore
        }
    }

    async function resolveProfile(handle: string, id: number) {
        try {
            const res = await fetch(
                `https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=${encodeURIComponent(handle)}`,
            );
            if (id !== reqIdRef.current) return;
            if (res.ok) {
                const d = await res.json();
                if (d.avatar) {
                    setAvatar(d.avatar);
                }
            }
        } catch {
            // ignore
        }
    }

    function selectSuggestion(handle: string, suggestionAvatar?: string) {
        setAvatar(suggestionAvatar ?? '');
        setSuggestions([]);
        setSuggestionsOpen(false);
        return handle;
    }

    function onKeydown(e: Pick<KeyboardEvent, 'key' | 'preventDefault'>) {
        if (!suggestionsOpen) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setSelectedIdx((i) => Math.min(i + 1, suggestions.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setSelectedIdx((i) => Math.max(i - 1, -1));
        } else if (e.key === 'Escape') {
            setSuggestionsOpen(false);
            setSelectedIdx(-1);
        }
    }

    useEffect(() => {
        return () => clearTimeout(debounceRef.current);
    }, []);

    return {
        avatar,
        suggestions,
        suggestionsOpen,
        selectedIdx,
        onInput,
        onKeydown,
        selectSuggestion,
        setSuggestionsOpen,
    };
}
