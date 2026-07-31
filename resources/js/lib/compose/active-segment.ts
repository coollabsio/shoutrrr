import { segmentRefs, type DocNode } from '@/lib/compose/tiptap-doc';

/**
 * ProseMirror-style node size, computed from the plain JSON doc (no live
 * editor): a text node's size is its character length, a leaf/atom node
 * (no `content` and no `text`, e.g. `sectionBreak`) is size 1, and any other
 * node's size is `2 + sum(child sizes)` — one position for its open token,
 * one for its close token.
 */
function nodeSize(node: DocNode): number {
    if (typeof node.text === 'string') {
        return node.text.length;
    }

    if (!node.content) {
        return 1;
    }

    return (
        2 + node.content.reduce((total, child) => total + nodeSize(child), 0)
    );
}

/**
 * Resolve which authored segment the caret (`from`, a ProseMirror position)
 * currently sits in. Walks the doc's top-level `content`, accumulating node
 * sizes to find the top-level block containing `from`, then counts the
 * `sectionBreak` nodes strictly before that block to get the authored
 * segment index — `segmentRefs(breakIds)[index]` is the segment's stable ref.
 *
 * Pure: takes plain JSON (`DocNode`) and a position, no live editor required,
 * so it's usable both from a Tiptap `onSelectionUpdate` handler and in
 * isolation in tests.
 */
export function activeSegmentRef(
    doc: DocNode,
    from: number,
    breakIds: string[],
): string {
    const refs = segmentRefs(breakIds);
    const topLevel = doc.content ?? [];

    let offset = 0;
    let breakCount = 0;

    for (let index = 0; index < topLevel.length; index++) {
        const node = topLevel[index];
        const size = nodeSize(node);
        const nodeEnd = offset + size;
        const isLastNode = index === topLevel.length - 1;

        if (from < nodeEnd || isLastNode) {
            if (node.type === 'sectionBreak') {
                breakCount += 1;
            }

            break;
        }

        if (node.type === 'sectionBreak') {
            breakCount += 1;
        }

        offset = nodeEnd;
    }

    return refs[Math.min(breakCount, refs.length - 1)];
}
