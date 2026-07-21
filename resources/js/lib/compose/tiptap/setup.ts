import { type Extensions } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import Link from '@tiptap/extension-link';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import { Placeholder, UndoRedo } from '@tiptap/extensions';

import { EmojiSuggest } from './emoji-suggest';
import { MentionPlaceholders } from './mention-placeholders';
import { SectionBreak } from './section-break';
import { SectionMarkers } from './section-markers';

/**
 * Build the composer's Tiptap extension list: a deliberately minimal plain-text
 * editor (Document/Paragraph/Text/UndoRedo/Placeholder/Link) plus the custom
 * SectionBreak node and SectionMarkers decoration plugin. The old product's
 * Mention/Hashtag extensions are intentionally dropped (out of scope).
 *
 * `compact` builds the single-message variant used by the engagement reply box:
 * the full composer editor minus thread splitting. It drops only SectionBreak and
 * SectionMarkers — a reply is one message, not a thread — and keeps everything
 * else, including MentionPlaceholders and the emoji typeahead. Dropping
 * SectionBreak also means the doc can never hold a `sectionBreak` node, so
 * `docToSegments` always returns exactly one segment — which is what lets the
 * reply box adapt this editor to a plain string.
 */
export function composerExtensions(
    opts: {
        placeholder?: string;
        emojiOpenRef?: { current: boolean } | null;
        compact?: boolean;
    } = {},
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
        ...(opts.compact ? [] : [SectionBreak]),
        MentionPlaceholders,
        EmojiSuggest.configure({ openRef: opts.emojiOpenRef ?? null }),
        ...(opts.compact ? [] : [SectionMarkers]),
    ];
}
