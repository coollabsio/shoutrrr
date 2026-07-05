import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { getSchema } from '@tiptap/core';
import { splitBlock } from '@tiptap/pm/commands';
import type { Node as PMNode } from '@tiptap/pm/model';
import { EditorState, TextSelection } from '@tiptap/pm/state';
import { describe, expect, it } from 'vitest';

import { docToSegments } from '@/lib/compose/tiptap-doc';
import { measureSectionAfterBreak } from '@/lib/compose/tiptap/section-break';
import { composerExtensions } from '@/lib/compose/tiptap/setup';

const schema = getSchema(composerExtensions());

function para(text: string) {
    return text === ''
        ? { type: 'paragraph' }
        : { type: 'paragraph', content: [{ type: 'text', text }] };
}

function docFrom(blocks: object[]): PMNode {
    return schema.nodeFromJSON({ type: 'doc', content: blocks });
}

/** Absolute position immediately before the top-level child at `index`. */
function posBeforeChild(doc: PMNode, index: number): number {
    let pos = 0;
    for (let i = 0; i < index; i++) {
        pos += doc.child(i).nodeSize;
    }

    return pos;
}

describe('measureSectionAfterBreak', () => {
    it('counts the paragraphs that follow the break up to the next break', () => {
        const doc = docFrom([
            para('first post'),
            { type: 'sectionBreak' },
            para('second'),
            para('post'),
            { type: 'sectionBreak' },
            para('third'),
        ]);

        // "second\npost" → 11 chars.
        expect(
            measureSectionAfterBreak(doc, posBeforeChild(doc, 1), 'bluesky'),
        ).toBe(11);
        // "third" → 5 chars.
        expect(
            measureSectionAfterBreak(doc, posBeforeChild(doc, 4), 'bluesky'),
        ).toBe(5);
    });

    it('returns 0 for a trailing break with no following paragraphs', () => {
        const doc = docFrom([para('only post'), { type: 'sectionBreak' }]);

        expect(
            measureSectionAfterBreak(doc, posBeforeChild(doc, 1), 'bluesky'),
        ).toBe(0);
    });
});

/** Run the command Shift+Enter is bound to and return the resulting segments. */
function shiftEnterAt(blocks: object[], cursor: number): string[] {
    const doc = docFrom(blocks);
    let state = EditorState.create({
        schema,
        doc,
        selection: TextSelection.create(doc, cursor),
    });

    splitBlock(state, (tr) => {
        state = state.apply(tr);
    });

    return docToSegments(state.doc.toJSON());
}

describe('Shift+Enter soft newline', () => {
    it('adds a newline within the post instead of a thread break', () => {
        // Caret at end of "hello" (pos 6: 1 for doc open + "hello").
        const segments = shiftEnterAt([para('hello')], 6);

        // One post, a trailing newline — never a second segment.
        expect(segments).toEqual(['hello\n']);
    });

    it('makes a blank line inside one post when pressed on an empty line', () => {
        // "hello" then an empty paragraph; caret in the empty paragraph.
        const blocks = [para('hello'), para('')];
        const doc = docFrom(blocks);
        // Position inside the second (empty) paragraph.
        const cursor = posBeforeChild(doc, 1) + 1;

        const segments = shiftEnterAt(blocks, cursor);

        // A blank line within the single post — not two threaded posts.
        expect(segments).toEqual(['hello\n\n']);
    });
});

describe('keyboard bindings', () => {
    it('binds Shift-Enter to a soft newline (splitBlock), not a section break', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/lib/compose/tiptap/section-break.ts',
            ),
            'utf8',
        );

        expect(source).toContain(
            "'Shift-Enter': () => this.editor.commands.splitBlock()",
        );
    });
});
