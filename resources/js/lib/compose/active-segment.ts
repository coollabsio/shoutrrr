import { segmentRefs, type DocNode } from '@/lib/compose/tiptap-doc';

/**
 * ProseMirror-style node size, computed from the plain JSON doc (no live
 * editor). Dispatches on `node.type`, NOT on whether `content` is present:
 * PM's `toJSON()` omits `content` for any childless node, including an
 * *empty* paragraph (a blank line) — which still occupies 2 positions (its
 * open + close tokens), not 1. Only genuine leaf/atom node types collapse
 * to size 1.
 *
 * - text node: size is its character length.
 * - `sectionBreak` (atom, never has content): size 1.
 * - everything else (`paragraph` and any other block node, empty or not):
 *   `2 + sum(child sizes)` — one position for the open token, one for the
 *   close.
 */
function nodeSize(node: DocNode): number {
    if (node.type === 'text') {
        return (node.text ?? '').length;
    }

    if (node.type === 'sectionBreak') {
        return 1;
    }

    return (
        2 +
        (node.content ?? []).reduce(
            (total, child) => total + nodeSize(child),
            0,
        )
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
