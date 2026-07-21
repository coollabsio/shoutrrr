import { getSchema } from '@tiptap/core';
import { splitBlock } from '@tiptap/pm/commands';
import { EditorState, TextSelection } from '@tiptap/pm/state';
import { describe, expect, it } from 'vitest';

import { docToSegments } from '@/lib/compose/tiptap-doc';
import { composerExtensions } from '@/lib/compose/tiptap/setup';

function names(opts?: Parameters<typeof composerExtensions>[0]): string[] {
    return composerExtensions(opts).map((extension) => extension.name);
}

const compactSchema = getSchema(composerExtensions({ compact: true }));

function para(text: string) {
    return text === ''
        ? { type: 'paragraph' }
        : { type: 'paragraph', content: [{ type: 'text', text }] };
}

/**
 * Split a paragraph in the compact schema and serialize the result. With
 * SectionBreak absent nothing overrides Enter, so this is what Enter does.
 */
function splitAt(blocks: object[], cursor: number): string[] {
    const doc = compactSchema.nodeFromJSON({ type: 'doc', content: blocks });
    let state = EditorState.create({
        schema: compactSchema,
        doc,
        selection: TextSelection.create(doc, cursor),
    });

    splitBlock(state, (tr) => {
        state = state.apply(tr);
    });

    return docToSegments(state.doc.toJSON());
}

describe('composerExtensions', () => {
    it('gives the composer the full thread + mention stack', () => {
        expect(names()).toEqual(
            expect.arrayContaining([
                'sectionBreak',
                'section_markers',
                'mentionPlaceholders',
                'emojiSuggest',
            ]),
        );
    });

    // Compact is the composer editor minus thread splitting: only SectionBreak
    // and SectionMarkers come out. Neither is inert unconfigured (SectionBreak's
    // keyboard shortcuts are always live; SectionMarkers defaults to
    // bluesky/300/autoSplit), so a reply must leave them out entirely.
    it('drops only the thread extensions in compact mode', () => {
        const compact = names({ compact: true });

        expect(compact).not.toContain('sectionBreak');
        expect(compact).not.toContain('section_markers');
    });

    it('keeps the plain-text editor, mentions, and emoji typeahead in compact mode', () => {
        expect(names({ compact: true })).toEqual(
            expect.arrayContaining([
                'doc',
                'paragraph',
                'text',
                'link',
                'placeholder',
                'mentionPlaceholders',
                'emojiSuggest',
            ]),
        );
    });

    it('leaves no sectionBreak node in the compact schema, so a reply is always one segment', () => {
        expect(getSchema(composerExtensions()).nodes).toHaveProperty(
            'sectionBreak',
        );
        expect(compactSchema.nodes).not.toHaveProperty('sectionBreak');
    });
});

// The reply box adapts this editor to a plain string with `segments[0] ?? ''`.
// That is only safe if a compact doc can never serialize to more than one
// segment — otherwise the tail of a reply would be silently dropped on send.
// The schema test above is what rules a second segment out; these cover the
// everyday editing that produces extra paragraphs.
describe('compact single-segment adapter', () => {
    it('keeps a split paragraph in one newline-joined segment', () => {
        // Caret at the end of "hello" (1 for the doc open + 5 chars).
        expect(splitAt([para('hello')], 6)).toEqual(['hello\n']);
    });

    it('keeps a blank line inside the one segment', () => {
        const blocks = [para('hello'), para('')];
        const doc = compactSchema.nodeFromJSON({
            type: 'doc',
            content: blocks,
        });
        // Inside the trailing empty paragraph.
        const cursor = doc.child(0).nodeSize + 1;

        expect(splitAt(blocks, cursor)).toEqual(['hello\n\n']);
    });
});
