import type { Editor } from '@tiptap/react';

/** Characters HANDLE_PATTERN allows to immediately follow a mention handle. */
const MENTION_BOUNDARY_PUNCTUATION = '.,!?;:';

/**
 * Move focus to the end of `label` in the editor, inserting a trailing space when
 * the mention is not already followed by whitespace or boundary punctuation.
 */
export function focusEditorAfterMentionLabel(
    editor: Editor,
    label: string,
): boolean {
    if (editor.isDestroyed) {
        return false;
    }

    if (label.trim() === '@') {
        editor.commands.focus('end');

        return false;
    }

    const end = findMentionLabelEnd(editor, label);
    if (end === null) {
        editor.commands.focus('end');

        return false;
    }

    const after = editor.state.doc.textBetween(end, end + 1);
    if (!endsMention(after)) {
        editor.chain().focus().insertContentAt(end, ' ').run();

        editor.commands.setTextSelection(end + 1);
        editor.commands.focus();

        return true;
    }

    editor.chain().focus().setTextSelection(end).run();

    return true;
}

/** Whether `label` appears in the editor as a real, boundary-delimited token. */
export function editorContainsMentionLabel(
    editor: Editor,
    label: string,
): boolean {
    return findMentionLabelEnd(editor, label) !== null;
}

function findMentionLabelEnd(editor: Editor, label: string): number | null {
    let end: number | null = null;

    editor.state.doc.descendants((node, pos) => {
        if (!node.isText || !node.text) {
            return;
        }

        const localEnd = findMentionLabelEndInText(node.text, label);
        if (localEnd !== null) {
            end = pos + localEnd;
        }
    });

    return end;
}

/**
 * Index just past the last boundary-delimited occurrence of `label` in `text`,
 * or null when it only appears inside a longer token (e.g. `@sam` inside
 * `@sammy`). A match at the very end of `text` counts as a valid boundary.
 */
export function findMentionLabelEndInText(
    text: string,
    label: string,
): number | null {
    if (label === '') {
        return null;
    }

    let end: number | null = null;
    let index = text.indexOf(label);
    while (index !== -1) {
        const before = index === 0 ? '' : text[index - 1];
        const after = text[index + label.length] ?? '';
        if (startsMention(before) && (after === '' || endsMention(after))) {
            end = index + label.length;
        }
        index = text.indexOf(label, index + 1);
    }

    return end;
}

/** A char that can precede a mention: the token start or whitespace. */
function startsMention(char: string): boolean {
    return char === '' || /\s/.test(char);
}

/**
 * A char that already terminates a mention: whitespace or the punctuation
 * HANDLE_PATTERN permits after a handle. The empty string (end of document) is
 * deliberately not a terminator, so a trailing space is added to seal the
 * mention when the caller positions the caret there.
 */
export function endsMention(char: string): boolean {
    return (
        char === ' ' ||
        char === '\n' ||
        (char !== '' && MENTION_BOUNDARY_PUNCTUATION.includes(char))
    );
}
