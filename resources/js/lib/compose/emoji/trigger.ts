/**
 * Detect an active emoji shortcode trigger at the caret: a `:` that begins a
 * word (start of block or after whitespace) followed by at least two of
 * `[a-z0-9_+-]`. The boundary rule keeps URLs (`http://`), times (`12:30`),
 * and emoticons (`:)`) from opening the picker.
 */
export function matchEmojiTrigger(
    textBefore: string,
    caretPos: number,
): { query: string; from: number } | null {
    const match = /(?:^|\s)(:[a-z0-9_+-]{2,})$/i.exec(textBefore);
    if (!match) {
        return null;
    }

    const token = match[1]; // ":smile"

    return { query: token.slice(1), from: caretPos - token.length };
}
