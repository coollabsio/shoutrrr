import { type Extensions } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import Link from '@tiptap/extension-link';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import { Placeholder, UndoRedo } from '@tiptap/extensions';

import { SectionBreak } from './section-break';
import { SectionMarkers } from './section-markers';

/**
 * Build the composer's Tiptap extension list: a deliberately minimal plain-text
 * editor (Document/Paragraph/Text/UndoRedo/Placeholder/Link) plus the custom
 * SectionBreak node and SectionMarkers decoration plugin. The old product's
 * Mention/Hashtag extensions are intentionally dropped (out of scope).
 */
export function composerExtensions(
    opts: { placeholder?: string } = {},
): Extensions {
    return [
        Document,
        Paragraph,
        Text,
        UndoRedo,
        Placeholder.configure({
            placeholder: opts.placeholder ?? 'Write something…',
        }),
        Link.configure({
            openOnClick: false,
            autolink: true,
            linkOnPaste: true,
        }),
        SectionBreak,
        SectionMarkers,
    ];
}
