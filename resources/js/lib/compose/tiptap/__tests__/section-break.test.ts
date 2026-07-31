import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { getSchema } from '@tiptap/core';
import { splitBlock } from '@tiptap/pm/commands';
import type { Node as PMNode } from '@tiptap/pm/model';
import { EditorState, TextSelection } from '@tiptap/pm/state';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    docToSegments,
    docToSegmentsWithBreaks,
    segmentsToDocWithBreaks,
} from '@/lib/compose/tiptap-doc';
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

describe('nextBreakId', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        vi.resetModules();
    });

    it('mints a distinct id on each call within one module load (page load)', async () => {
        vi.resetModules();
        const { nextBreakId } =
            await import('@/lib/compose/tiptap/section-break');

        expect(nextBreakId()).not.toBe(nextBreakId());
    });

    it('cannot repeat the previous page load’s first id, even though both loads restart their internal counter at 1', async () => {
        // Two independent "page loads" are two fresh module instances. Force
        // each one's random session token so the test is deterministic while
        // still proving the counter alone (which always starts at 1) is not
        // what prevents the collision described in the bug — the per-load
        // token namespace is.
        vi.resetModules();
        vi.spyOn(Math, 'random').mockReturnValueOnce(0.111111);
        const firstLoad = await import('@/lib/compose/tiptap/section-break');
        const firstLoadFirstId = firstLoad.nextBreakId();

        vi.resetModules();
        vi.spyOn(Math, 'random').mockReturnValueOnce(0.999999);
        const secondLoad = await import('@/lib/compose/tiptap/section-break');
        const secondLoadFirstId = secondLoad.nextBreakId();

        // Both are the first id minted in their respective session...
        expect(firstLoadFirstId.endsWith('_1')).toBe(true);
        expect(secondLoadFirstId.endsWith('_1')).toBe(true);
        // ...but the session-token namespace keeps them from colliding, unlike
        // a bare `bk_1` counter that would repeat across every page load.
        expect(firstLoadFirstId).not.toBe(secondLoadFirstId);
    });

    it('respects ids restored via segmentsToDocWithBreaks/docToSegmentsWithBreaks regardless of nextBreakId', () => {
        // A reloaded draft's persisted breakIds (e.g. `bk_1`, `bk_2` minted by
        // an older build of this id scheme) round-trip verbatim — restoring a
        // draft never calls nextBreakId, so old-format ids keep working.
        const doc = segmentsToDocWithBreaks(['a', 'b', 'c'], ['bk_1', 'bk_2']);

        expect(docToSegmentsWithBreaks(doc)).toEqual({
            segments: ['a', 'b', 'c'],
            breakIds: ['bk_1', 'bk_2'],
        });
    });
});
