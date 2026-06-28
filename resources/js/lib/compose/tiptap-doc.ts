// Minimal shape of Tiptap/ProseMirror JSON we care about.
export type DocNode = {
    type: string;
    text?: string;
    content?: DocNode[];
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
