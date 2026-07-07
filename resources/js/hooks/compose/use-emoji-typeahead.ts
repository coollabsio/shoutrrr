import { type Editor } from '@tiptap/react';
import { type RefObject, useEffect, useRef, useState } from 'react';

import { loadEmojiIndex, rankEmoji } from '@/lib/compose/emoji/shortcode-index';
import type { EmojiMatch, EmojiSkinTone } from '@/lib/compose/emoji/types';
import {
    emojiSuggestKey,
    type EmojiSuggestState,
} from '@/lib/compose/tiptap/emoji-suggest';

const INACTIVE: EmojiSuggestState = {
    active: false,
    query: '',
    from: 0,
    to: 0,
};

type Options = {
    /** The TipTap editor (null until it mounts). */
    editor: Editor | null;
    /** The editor's relatively-positioned wrapper, for caret-anchoring. */
    containerRef: RefObject<HTMLDivElement | null>;
    /**
     * Written live so the emojiSuggest plugin's handleKeyDown gates key
     * consumption on the popover actually being open. Created by the caller and
     * also handed to `composerExtensions`, so both sides share one ref.
     */
    openRef: RefObject<boolean>;
    /** Active skin tone applied to ranked results. */
    skinTone: EmojiSkinTone;
    /** False on a read-only post — suppresses the popover entirely. */
    editable: boolean;
    /** Record a chosen emoji as recently used. */
    onInsert?: (emoji: string) => void;
};

export type EmojiTypeahead = {
    /** Whether the typeahead popover should be shown. */
    open: boolean;
    matches: EmojiMatch[];
    activeIndex: number;
    /** Zero-size element pinned to the active `:query` for the popover anchor. */
    anchorRef: RefObject<HTMLDivElement | null>;
    /** Dismiss for the current query (Escape / click-away). */
    dismiss: () => void;
    /** Commit a match, replacing the active `:query`. */
    select: (match: EmojiMatch) => void;
};

/**
 * Owns the inline `:shortcode` emoji typeahead: it mirrors the emojiSuggest
 * ProseMirror plugin's trigger state into React, fetches + ranks matches, bridges
 * the plugin's keyboard-forwarded CustomEvents (nav / commit / dismiss) back into
 * React, and keeps a caret-anchored element positioned for the popover. The
 * rendering lives in `EmojiSuggestPopover`; this hook is the behaviour.
 */
export function useEmojiTypeahead({
    editor,
    containerRef,
    openRef,
    skinTone,
    editable,
    onInsert,
}: Options): EmojiTypeahead {
    const anchorRef = useRef<HTMLDivElement>(null);
    const [suggest, setSuggest] = useState<EmojiSuggestState>(INACTIVE);
    const [matches, setMatches] = useState<EmojiMatch[]>([]);
    const [activeIndex, setActiveIndex] = useState(0);
    // State (not a ref) so `open` derives from it without reading a mutable ref
    // during render.
    const [dismissed, setDismissed] = useState(false);

    // The CustomEvent handlers below are bound once per editor, so they read the
    // latest state (and onInsert, whose identity changes across renders) through
    // refs rather than a stale render closure.
    const onInsertRef = useRef(onInsert);
    onInsertRef.current = onInsert;
    const latest = useRef({ suggest, matches, activeIndex });
    latest.current = { suggest, matches, activeIndex };

    const open = editable && suggest.active && !dismissed && matches.length > 0;
    openRef.current = open;

    // Mirror the plugin's trigger state into React on every transaction.
    useEffect(() => {
        if (!editor) {
            return;
        }
        const view = editor;
        function sync() {
            setSuggest(emojiSuggestKey.getState(view.state) ?? INACTIVE);
        }
        view.on('transaction', sync);
        sync();

        return () => {
            view.off('transaction', sync);
        };
    }, [editor]);

    // Fetch + rank matches when the query changes. A new query clears any prior
    // dismissal, so re-typing after Escape reopens the popover.
    useEffect(() => {
        if (!suggest.active) {
            setMatches([]);

            return;
        }
        setDismissed(false);
        let cancelled = false;
        loadEmojiIndex()
            .then((index) => {
                if (cancelled) {
                    return;
                }
                setMatches(rankEmoji(index, suggest.query, { skinTone }));
                setActiveIndex(0);
            })
            .catch(() => {
                if (!cancelled) {
                    setMatches([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [suggest.active, suggest.query, skinTone]);

    // Replace the active `:query` with the chosen emoji and refocus.
    function insertAt(match: EmojiMatch, from: number, to: number) {
        if (!editor) {
            return;
        }
        editor
            .chain()
            .focus()
            .insertContentAt({ from, to }, `${match.emoji} `)
            .run();
        onInsertRef.current?.(match.emoji);
    }

    // Bridge the plugin's keyboard-forwarded CustomEvents to React state.
    useEffect(() => {
        const element = editor?.view.dom;
        if (!element) {
            return;
        }

        function onNav(event: Event) {
            const delta = (event as CustomEvent<{ delta: number }>).detail
                .delta;
            const count = latest.current.matches.length;
            if (count === 0) {
                return;
            }
            setActiveIndex((index) => (index + delta + count) % count);
        }

        function onCommit() {
            const {
                suggest: active,
                matches: current,
                activeIndex: index,
            } = latest.current;
            const match = current[index];
            if (match) {
                insertAt(match, active.from, active.to);
            }
        }

        function onDismiss() {
            setDismissed(true);
        }

        element.addEventListener('composer:emoji-nav', onNav);
        element.addEventListener('composer:emoji-commit', onCommit);
        element.addEventListener('composer:emoji-dismiss', onDismiss);

        return () => {
            element.removeEventListener('composer:emoji-nav', onNav);
            element.removeEventListener('composer:emoji-commit', onCommit);
            element.removeEventListener('composer:emoji-dismiss', onDismiss);
        };
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [editor]);

    // Pin the floating anchor to the start of the active `:query`.
    useEffect(() => {
        const container = containerRef.current;
        const anchor = anchorRef.current;
        if (!editor || !suggest.active || !container || !anchor) {
            return;
        }
        const caret = editor.view.coordsAtPos(suggest.from);
        const rect = container.getBoundingClientRect();
        anchor.style.left = `${caret.left - rect.left}px`;
        anchor.style.top = `${caret.top - rect.top}px`;
        anchor.style.height = `${caret.bottom - caret.top}px`;
    }, [editor, containerRef, suggest.active, suggest.from]);

    return {
        open,
        matches,
        activeIndex,
        anchorRef,
        dismiss: () => setDismissed(true),
        select: (match) =>
            insertAt(
                match,
                latest.current.suggest.from,
                latest.current.suggest.to,
            ),
    };
}
