import { mergeAttributes, Node } from '@tiptap/core';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        sectionBreak: {
            insertSectionBreak: () => ReturnType;
        };
    }
}

/**
 * Manual thread-break atom node. Renders as a non-editable `<div data-section-break>`
 * and serializes to the canonical `---` boundary the server-side PostSplitter splits on.
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
            'Mod-Shift-Enter': () => this.editor.commands.insertSectionBreak(),
        };
    },
});
