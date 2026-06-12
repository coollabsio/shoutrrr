// Minimal shape of Tiptap/ProseMirror JSON we care about.
export type DocNode = {
    type: string;
    text?: string;
    content?: DocNode[];
};

/**
 * Serialize a Tiptap doc to canonical base text: paragraphs become lines,
 * section-break nodes become a `---` break line (the manual thread-break marker
 * that the server-side PostSplitter splits on).
 */
export function docToBaseText(doc: DocNode): string {
    const blocks = doc.content ?? [];

    return blocks
        .map((node) => {
            if (node.type === 'sectionBreak') {
                return '---';
            }

            return (node.content ?? [])
                .map((child) => child.text ?? '')
                .join('');
        })
        .join('\n');
}

/**
 * Rebuild a Tiptap doc from canonical base text (the inverse of docToBaseText).
 */
export function baseTextToDoc(text: string): DocNode {
    const content: DocNode[] = [];

    for (const line of text.split('\n')) {
        if (/^\s*---\s*$/.test(line)) {
            content.push({ type: 'sectionBreak' });

            continue;
        }

        content.push(
            line === ''
                ? { type: 'paragraph' }
                : {
                      type: 'paragraph',
                      content: [{ type: 'text', text: line }],
                  },
        );
    }

    if (content.length === 0) {
        content.push({ type: 'paragraph' });
    }

    return { type: 'doc', content };
}
