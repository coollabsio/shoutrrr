import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import type { Editor } from '@tiptap/react';

import {
    endsMentionBoundary,
    startsMentionBoundary,
} from '@/lib/compose/mentions';

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

    const end = findMentionLabelEnd(editor.state.doc, label);
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
    return findMentionLabelEnd(editor.state.doc, label) !== null;
}

/**
 * Delete the `@label` token from the editor and keep focus there — used when the
 * user backspaces or escapes an empty mention. Returns true when a token was
 * removed.
 */
export function removeMentionLabel(editor: Editor, label: string): boolean {
    if (editor.isDestroyed) {
        return false;
    }

    const range = mentionRemovalRange(editor.state.doc, label);
    if (range === null) {
        return false;
    }

    editor.chain().focus().deleteRange(range).run();

    return true;
}

/**
 * The `{ from, to }` document range to delete to remove `label`'s token,
 * swallowing a single leading space so deleting the `@` in `hi @` leaves `hi`,
 * not a dangling `hi `. Null when the label is not present as a real token.
 */
export function mentionRemovalRange(
    doc: ProseMirrorNode,
    label: string,
): { from: number; to: number } | null {
    const to = findMentionLabelEnd(doc, label);
    if (to === null) {
        return null;
    }

    let from = to - label.length;
    if (from > 0 && doc.textBetween(from - 1, from) === ' ') {
        from -= 1;
    }

    return { from, to };
}

/**
 * Document position of the start of `label`'s boundary-delimited occurrence —
 * the `@` itself — or null when the label isn't present as a real token. Used to
 * pin the mention picker beside the `@` so it stays put as the name is typed.
 */
export function findMentionLabelStart(
    editor: Editor,
    label: string,
): number | null {
    const end = findMentionLabelEnd(editor.state.doc, label);

    return end === null ? null : end - label.length;
}

function findMentionLabelEnd(
    doc: ProseMirrorNode,
    label: string,
): number | null {
    let end: number | null = null;

    doc.descendants((node, pos) => {
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
        if (startsMentionBoundary(before) && endsMentionBoundary(after)) {
            end = index + label.length;
        }
        index = text.indexOf(label, index + 1);
    }

    return end;
}

/**
 * A char that already terminates a mention: whitespace or the punctuation
 * HANDLE_PATTERN permits after a handle. The empty string (end of document) is
 * deliberately not a terminator, so a trailing space is added to seal the
 * mention when the caller positions the caret there.
 */
export function endsMention(char: string): boolean {
    return char !== '' && endsMentionBoundary(char);
}
