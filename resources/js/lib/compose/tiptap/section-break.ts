import { mergeAttributes, Node } from '@tiptap/core';
import type { Node as PMNode } from '@tiptap/pm/model';

import { measure } from '@/lib/compose/section-split';
import { sectionMarkersKey } from '@/lib/compose/tiptap/section-markers';
import type { PlatformName } from '@/types/compose';

/**
 * Measure the section that begins right after the manual break at `breakPos` —
 * the "next post". Collects the paragraph text from the block following the
 * break up to (but not including) the next break or the end of the document,
 * joined by newlines to mirror the canonical base text the splitter consumes.
 */
export function measureSectionAfterBreak(
    doc: PMNode,
    breakPos: number,
    platform: PlatformName,
): number {
    const breakIndex = doc.resolve(breakPos).index(0);
    const lines: string[] = [];

    for (let i = breakIndex + 1; i < doc.childCount; i++) {
        const child = doc.child(i);
        if (child.type.name === 'sectionBreak') {
            break;
        }
        if (child.type.name === 'paragraph') {
            lines.push(child.textContent);
        }
    }

    return measure(lines.join('\n'), platform);
}

export function shouldConvertEmptyParagraphToSectionBreak({
    currentBlockType,
    currentText,
    previousBlockType,
}: {
    currentBlockType: string;
    currentText: string;
    previousBlockType: string | null;
}): boolean {
    return (
        currentBlockType === 'paragraph' &&
        currentText.length === 0 &&
        previousBlockType !== null &&
        previousBlockType !== 'sectionBreak'
    );
}

export function shouldDeleteSectionBreakWithTrailingEmptyParagraph({
    currentBlockType,
    currentText,
    previousBlockType,
}: {
    currentBlockType: string;
    currentText: string;
    previousBlockType: string | null;
}): boolean {
    return (
        currentBlockType === 'paragraph' &&
        currentText.length === 0 &&
        previousBlockType === 'sectionBreak'
    );
}

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        sectionBreak: {
            insertSectionBreak: () => ReturnType;
        };
    }
}

/**
 * Manual thread-break atom node. Renders as a non-editable `<div data-section-break>`
 * and marks a segment boundary — `docToSegments` ends the current segment here, so
 * the server-side PostSplitter receives it as an array boundary, not an in-text marker.
 */
export const SectionBreak = Node.create({
    name: 'sectionBreak',
    group: 'block',
    atom: true,
    selectable: true,

    parseHTML() {
        return [{ tag: 'div[data-section-break]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, { 'data-section-break': '' }),
        ];
    },

    /**
     * Render the on-screen marker as a live chip showing the character count of
     * the post that begins after this break — the count the auto-split markers
     * already show, brought to manual breaks instead of a static "Next post"
     * label. The count tracks every edit by recomputing on each transaction.
     */
    addNodeView() {
        return ({ editor, getPos }) => {
            const dom = document.createElement('div');
            dom.setAttribute('data-section-break', '');
            dom.className = 'section-marker';
            dom.setAttribute('contenteditable', 'false');
            dom.setAttribute('aria-hidden', 'true');
            dom.innerHTML =
                '<span class="sm-rule"></span>' +
                '<span class="sm-chip"><span class="sm-count"></span></span>' +
                '<span class="sm-rule"></span>';
            const countEl = dom.querySelector<HTMLElement>('.sm-count')!;

            let lastLabel = '';
            let lastState = '';
            const render = () => {
                const pos = typeof getPos === 'function' ? getPos() : null;
                if (pos === null || pos === undefined) {
                    return;
                }
                const config = sectionMarkersKey.getState(editor.state)?.config;
                const platform = config?.platform ?? 'bluesky';
                const limit =
                    config && config.limit > 0 ? config.limit : Infinity;
                const count = measureSectionAfterBreak(
                    editor.state.doc,
                    pos,
                    platform,
                );
                const state =
                    count > limit
                        ? 'over'
                        : count >= limit * 0.9
                          ? 'warn'
                          : 'ok';
                const label = `${count}/${Number.isFinite(limit) ? limit : '∞'}`;
                if (label !== lastLabel) {
                    countEl.textContent = label;
                    lastLabel = label;
                }
                if (state !== lastState) {
                    dom.dataset.state = state;
                    lastState = state;
                }
            };

            render();
            const onTransaction = () => render();
            editor.on('transaction', onTransaction);

            return {
                dom,
                update: (node) => node.type.name === 'sectionBreak',
                ignoreMutation: () => true,
                destroy: () => editor.off('transaction', onTransaction),
            };
        };
    },

    addCommands() {
        return {
            insertSectionBreak:
                () =>
                ({ commands }) =>
                    commands.insertContent({ type: this.name }),
        };
    },

    addKeyboardShortcuts() {
        return {
            Enter: () => {
                const { selection } = this.editor.state;
                if (!selection.empty) {
                    return false;
                }

                const { $from } = selection;
                const containerDepth = $from.depth - 1;
                const currentIndex = $from.index(containerDepth);
                const previousBlock =
                    currentIndex > 0
                        ? $from.node(containerDepth).child(currentIndex - 1)
                        : null;

                if (
                    !shouldConvertEmptyParagraphToSectionBreak({
                        currentBlockType: $from.parent.type.name,
                        currentText: $from.parent.textContent,
                        previousBlockType: previousBlock?.type.name ?? null,
                    })
                ) {
                    return false;
                }

                return this.editor
                    .chain()
                    .deleteRange({
                        from: $from.before($from.depth),
                        to: $from.after($from.depth),
                    })
                    .insertContent([{ type: this.name }, { type: 'paragraph' }])
                    .focus()
                    .run();
            },
            Backspace: () => {
                const { selection } = this.editor.state;
                if (!selection.empty) {
                    return false;
                }

                const { $from } = selection;
                const containerDepth = $from.depth - 1;
                const currentIndex = $from.index(containerDepth);
                const previousBlock =
                    currentIndex > 0
                        ? $from.node(containerDepth).child(currentIndex - 1)
                        : null;

                if (
                    !shouldDeleteSectionBreakWithTrailingEmptyParagraph({
                        currentBlockType: $from.parent.type.name,
                        currentText: $from.parent.textContent,
                        previousBlockType: previousBlock?.type.name ?? null,
                    })
                ) {
                    return false;
                }

                const currentStart = $from.before($from.depth);
                const previousStart =
                    currentStart - (previousBlock?.nodeSize ?? 0);

                return this.editor
                    .chain()
                    .deleteRange({
                        from: previousStart,
                        to: $from.after($from.depth),
                    })
                    .focus()
                    .run();
            },
            // Shift+Enter is the "soft" newline: split the paragraph so the
            // caret moves to the next line within the same post, without ever
            // converting to a section break the way an empty-line Enter does.
            // Two Shift+Enters therefore make a blank line inside one post.
            'Shift-Enter': () => this.editor.commands.splitBlock(),
            'Mod-Shift-Enter': () => this.editor.commands.insertSectionBreak(),
        };
    },
});
