import { getSchema } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { describe, expect, it } from 'vitest';

import {
    endsMention,
    findMentionLabelEndInText,
    mentionRemovalRange,
} from '@/lib/compose/tiptap/mention-focus';
import { composerExtensions } from '@/lib/compose/tiptap/setup';

const schema = getSchema(composerExtensions());

function docFromText(text: string): ProseMirrorNode {
    return schema.nodeFromJSON({
        type: 'doc',
        content: [
            text === ''
                ? { type: 'paragraph' }
                : { type: 'paragraph', content: [{ type: 'text', text }] },
        ],
    });
}

describe('findMentionLabelEndInText', () => {
    it('locates a boundary-delimited mention and returns the index past it', () => {
        expect(findMentionLabelEndInText('hi @john', '@john')).toBe(8);
        expect(findMentionLabelEndInText('@john here', '@john')).toBe(5);
    });

    it('treats trailing boundary punctuation as a valid mention end', () => {
        expect(findMentionLabelEndInText('@john!', '@john')).toBe(5);
        expect(findMentionLabelEndInText('hey @john, ok', '@john')).toBe(9);
    });

    it('ignores the label when it only appears inside a longer token', () => {
        expect(findMentionLabelEndInText('@sammy', '@sam')).toBeNull();
        expect(findMentionLabelEndInText('email@sam', '@sam')).toBeNull();
    });

    it('matches the real token even when a longer overlapping one follows', () => {
        // `@sam` must resolve to the standalone mention, not the `@sam` inside `@sammy`.
        expect(findMentionLabelEndInText('@sam and @sammy', '@sam')).toBe(4);
    });

    it('returns the last boundary-delimited occurrence', () => {
        expect(findMentionLabelEndInText('@a then @a', '@a')).toBe(10);
    });

    it('returns null for an absent label or empty needle', () => {
        expect(findMentionLabelEndInText('nothing here', '@john')).toBeNull();
        expect(findMentionLabelEndInText('@john', '')).toBeNull();
    });
});

describe('mentionRemovalRange', () => {
    it('swallows a single leading space so `hi @` deletes back to `hi`', () => {
        // "hi @": '@' is the 4th char → doc positions 4–5; the space at 3–4 is
        // swallowed, leaving the range 3–5.
        expect(mentionRemovalRange(docFromText('hi @'), '@')).toEqual({
            from: 3,
            to: 5,
        });
    });

    it('removes only the token when there is no leading space', () => {
        expect(mentionRemovalRange(docFromText('@'), '@')).toEqual({
            from: 1,
            to: 2,
        });
        expect(mentionRemovalRange(docFromText('@guest'), '@guest')).toEqual({
            from: 1,
            to: 7,
        });
    });

    it('targets a named mention and its leading space', () => {
        expect(
            mentionRemovalRange(docFromText('hey @guest'), '@guest'),
        ).toEqual({ from: 4, to: 11 });
    });

    it('returns null when the label is absent', () => {
        expect(mentionRemovalRange(docFromText('hello'), '@')).toBeNull();
    });
});

describe('endsMention', () => {
    it('treats whitespace and handle punctuation as terminators', () => {
        for (const char of [' ', '\n', '.', ',', '!', '?', ';', ':']) {
            expect(endsMention(char)).toBe(true);
        }
    });

    it('does not treat word characters or the document end as terminators', () => {
        expect(endsMention('a')).toBe(false);
        expect(endsMention('@')).toBe(false);
        expect(endsMention('')).toBe(false);
    });
});
