/** Frimousse skin-tone identifiers, persisted verbatim and reused for the typeahead. */
export type EmojiSkinTone =
    | 'none'
    | 'light'
    | 'medium-light'
    | 'medium'
    | 'medium-dark'
    | 'dark';

/** A normalized emoji ready for search and skin-tone application. */
export type EmojiEntry = {
    emoji: string;
    label: string;
    hexcode: string;
    tags: string[];
    shortcodes: string[];
    skins: { tone: number; emoji: string }[];
};

/** A single ranked typeahead result. */
export type EmojiMatch = {
    emoji: string;
    label: string;
    shortcode: string;
};

/** emojibase `data.json` row shape (only the fields we use). */
export type RawEmoji = {
    hexcode: string;
    emoji: string;
    label: string;
    tags?: string[];
    skins?: { hexcode: string; emoji: string; tone?: number | number[] }[];
};
