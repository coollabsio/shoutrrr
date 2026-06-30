import { Extension } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

/**
 * Matches serialized mention tokens (`{{mention:id}}`) and standalone `@handle`s.
 * The `@handle` branch requires a leading boundary (start of text or whitespace)
 * via the `(?<!\S)` lookbehind, so the `@domain` part of an email such as
 * `raulp@hey.com` is not mistaken for a handle and highlighted.
 */
const TOKEN_PATTERN =
    /\{\{mention:[a-zA-Z0-9_-]+\}\}|(?<!\S)@[a-zA-Z0-9_.-]{0,50}(?=\s|$|[.,!?;:])/g;

export type MentionDecoration = { start: number; end: number; id: string };

/**
 * Yields the mention tokens to decorate within a text run — each match's absolute
 * range (offset by `base`) and its derived `data-mention-id`. Lazy, so callers
 * decorate in a single pass with no intermediate array.
 */
export function* mentionDecorations(
    text: string,
    base = 0,
): Generator<MentionDecoration> {
    for (const match of text.matchAll(TOKEN_PATTERN)) {
        const start = base + (match.index ?? 0);
        yield {
            start,
            end: start + match[0].length,
            id: match[0].replace(/^@/, '').replace(/[^a-zA-Z0-9_-]+/g, '-'),
        };
    }
}

export const MentionPlaceholders = Extension.create({
    name: 'mentionPlaceholders',

    addProseMirrorPlugins() {
        return [
            new Plugin({
                props: {
                    handleClick(view, position, event) {
                        const target = event.target;
                        if (!(target instanceof Element)) {
                            return false;
                        }

                        const mention = target.closest('[data-mention-id]');
                        const id = mention?.getAttribute('data-mention-id');
                        if (!id) {
                            return false;
                        }

                        view.dom.dispatchEvent(
                            new CustomEvent('composer:mention-click', {
                                bubbles: true,
                                detail: { id },
                            }),
                        );

                        return true;
                    },
                    decorations(state) {
                        const decorations: Decoration[] = [];

                        state.doc.descendants((node, position) => {
                            if (!node.isText || !node.text) {
                                return;
                            }

                            for (const { start, end, id } of mentionDecorations(
                                node.text,
                                position,
                            )) {
                                decorations.push(
                                    Decoration.inline(start, end, {
                                        class: 'cursor-pointer rounded-md bg-primary/10 px-1 py-0.5 font-medium text-primary ring-1 ring-primary/20',
                                        'data-mention-id': id,
                                    }),
                                );
                            }
                        });

                        return DecorationSet.create(state.doc, decorations);
                    },
                },
            }),
        ];
    },
});
