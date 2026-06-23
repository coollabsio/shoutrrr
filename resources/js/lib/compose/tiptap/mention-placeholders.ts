import { Extension } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

const TOKEN_PATTERN =
    /\{\{mention:[a-zA-Z0-9_-]+\}\}|@[a-zA-Z0-9_.-]{0,50}(?=\s|$|[.,!?;:])/g;

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

                            for (const match of node.text.matchAll(
                                TOKEN_PATTERN,
                            )) {
                                const start = position + (match.index ?? 0);
                                const end = start + match[0].length;
                                decorations.push(
                                    Decoration.inline(start, end, {
                                        class: 'cursor-pointer rounded-md bg-primary/10 px-1 py-0.5 font-medium text-primary ring-1 ring-primary/20',
                                        'data-mention-id': (
                                            match[1] ??
                                            match[0].replace(/^@/, '')
                                        ).replace(/[^a-zA-Z0-9_-]+/g, '-'),
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
