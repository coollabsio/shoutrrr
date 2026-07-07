import { Extension } from '@tiptap/core';
import { Plugin, PluginKey, type EditorState } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

import { matchEmojiTrigger } from '@/lib/compose/emoji/trigger';

export type EmojiSuggestState = {
    active: boolean;
    query: string;
    from: number;
    to: number;
};

export const emojiSuggestKey = new PluginKey<EmojiSuggestState>('emojiSuggest');

const INACTIVE: EmojiSuggestState = {
    active: false,
    query: '',
    from: 0,
    to: 0,
};

/** Derive the trigger state from the text before an empty caret. */
function computeState(state: EditorState): EmojiSuggestState {
    const { selection } = state;
    if (!selection.empty) {
        return INACTIVE;
    }

    const { $from } = selection;
    const textBefore = $from.parent.textBetween(
        0,
        $from.parentOffset,
        undefined,
        '￼',
    );
    const match = matchEmojiTrigger(textBefore, selection.from);
    if (!match) {
        return INACTIVE;
    }

    return {
        active: true,
        query: match.query,
        from: match.from,
        to: selection.from,
    };
}

const NAV_EVENTS: Record<string, { type: string; delta?: number }> = {
    ArrowDown: { type: 'composer:emoji-nav', delta: 1 },
    ArrowUp: { type: 'composer:emoji-nav', delta: -1 },
    Enter: { type: 'composer:emoji-commit' },
    Tab: { type: 'composer:emoji-commit' },
    Escape: { type: 'composer:emoji-dismiss' },
};

/**
 * Tracks an active `:shortcode` trigger and, while active, forwards navigation
 * keys to React via DOM CustomEvents (mirrors `composer:mention-click`). The
 * popover UI lives in EditorBody; this plugin owns only trigger state + keys.
 */
export const EmojiSuggest = Extension.create({
    name: 'emojiSuggest',

    addOptions() {
        return {
            openRef: null as { current: boolean } | null,
        };
    },

    addProseMirrorPlugins() {
        const openRef = this.options.openRef;

        return [
            new Plugin<EmojiSuggestState>({
                key: emojiSuggestKey,
                state: {
                    init: () => INACTIVE,
                    apply: (_tr, _value, _old, newState) =>
                        computeState(newState),
                },
                props: {
                    decorations(state) {
                        const current = emojiSuggestKey.getState(state);
                        if (!current?.active) {
                            return null;
                        }

                        return DecorationSet.create(state.doc, [
                            Decoration.inline(current.from, current.to, {
                                class: 'rounded bg-primary/10 text-primary',
                            }),
                        ]);
                    },
                    handleKeyDown(view, event) {
                        const current = emojiSuggestKey.getState(view.state);
                        if (!current?.active || !openRef?.current) {
                            return false;
                        }

                        const mapping = NAV_EVENTS[event.key];
                        if (!mapping) {
                            return false;
                        }

                        view.dom.dispatchEvent(
                            new CustomEvent(mapping.type, {
                                bubbles: true,
                                detail: { delta: mapping.delta ?? 0 },
                            }),
                        );

                        return true;
                    },
                },
            }),
        ];
    },
});
