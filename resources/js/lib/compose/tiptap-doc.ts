// Minimal shape of Tiptap/ProseMirror JSON we care about.
export type DocNode = {
    type: string;
    text?: string;
    content?: DocNode[];
    attrs?: { breakId?: string };
};

/**
 * Serialize a Tiptap doc to the structured author body: an array of segments.
 * Paragraphs become `\n`-joined lines within a segment; each `sectionBreak`
 * node ends the current segment and starts the next. There is NO `---` marker —
 * a literal `---` a user types is just paragraph text.
 */
export function docToSegments(doc: DocNode): string[] {
    const segments: string[] = [];
    let current: string[] = [];

    for (const node of doc.content ?? []) {
        if (node.type === 'sectionBreak') {
            segments.push(current.join('\n'));
            current = [];

            continue;
        }

        current.push(
            (node.content ?? []).map((child) => child.text ?? '').join(''),
        );
    }

    segments.push(current.join('\n'));

    return segments;
}

/**
 * Rebuild a Tiptap doc from the structured segments (inverse of docToSegments):
 * each segment's lines become paragraphs, with a `sectionBreak` node between
 * segments.
 */
export function segmentsToDoc(segments: string[]): DocNode {
    const content: DocNode[] = [];

    segments.forEach((segment, index) => {
        if (index > 0) {
            content.push({ type: 'sectionBreak' });
        }

        for (const line of segment.split('\n')) {
            content.push(
                line === ''
                    ? { type: 'paragraph' }
                    : {
                          type: 'paragraph',
                          content: [{ type: 'text', text: line }],
                      },
            );
        }
    });

    if (content.length === 0) {
        content.push({ type: 'paragraph' });
    }

    return { type: 'doc', content };
}

/**
 * Break-aware variant of `docToSegments`: also returns the stable `breakId`
 * of the section break that opens each non-first segment. `breakIds[i]` is
 * the id of the break opening `segments[i + 1]`, so
 * `breakIds.length === segments.length - 1`. A break missing its id (should
 * not happen once `SectionBreak` mints ids, but kept as a defensive
 * fallback) gets a deterministic placeholder based on its ordinal position.
 */
export function docToSegmentsWithBreaks(doc: DocNode): {
    segments: string[];
    breakIds: string[];
} {
    const segments: string[] = [];
    const breakIds: string[] = [];
    let current: string[] = [];

    for (const node of doc.content ?? []) {
        if (node.type === 'sectionBreak') {
            segments.push(current.join('\n'));
            current = [];
            breakIds.push(node.attrs?.breakId ?? 'b' + breakIds.length);

            continue;
        }

        current.push(
            (node.content ?? []).map((child) => child.text ?? '').join(''),
        );
    }

    segments.push(current.join('\n'));

    return { segments, breakIds };
}

/**
 * Inverse of `docToSegmentsWithBreaks`: rebuilds the doc and assigns
 * `breakIds[i - 1]` as the `breakId` attr of the section break opening the
 * `i`-th segment.
 */
export function segmentsToDocWithBreaks(
    segments: string[],
    breakIds: string[],
): DocNode {
    const content: DocNode[] = [];

    segments.forEach((segment, index) => {
        if (index > 0) {
            content.push({
                type: 'sectionBreak',
                attrs: { breakId: breakIds[index - 1] },
            });
        }

        for (const line of segment.split('\n')) {
            content.push(
                line === ''
                    ? { type: 'paragraph' }
                    : {
                          type: 'paragraph',
                          content: [{ type: 'text', text: line }],
                      },
            );
        }
    });

    if (content.length === 0) {
        content.push({ type: 'paragraph' });
    }

    return { type: 'doc', content };
}

/**
 * The canonical `segmentId` per segment: the first segment is always
 * `__head__` (it has no opening break), and each subsequent segment is
 * keyed by the id of the break that opens it.
 */
export function segmentRefs(breakIds: string[]): string[] {
    return ['__head__', ...breakIds];
}
