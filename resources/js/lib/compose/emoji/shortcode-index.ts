import type { EmojiEntry, EmojiMatch, EmojiSkinTone, RawEmoji } from './types';

const TONE_NUMBER: Record<EmojiSkinTone, number> = {
    none: 0,
    light: 1,
    'medium-light': 2,
    medium: 3,
    'medium-dark': 4,
    dark: 5,
};

/** Normalize a shortcodes entry (string | string[]) into a flat list. */
function toShortcodes(value: string | string[] | undefined): string[] {
    if (!value) {
        return [];
    }

    return (Array.isArray(value) ? value : [value]).map((code) =>
        code.toLowerCase(),
    );
}

/** Join emojibase `data.json` rows with a shortcodes preset, keyed by hexcode. */
export function buildEmojiIndex(
    data: RawEmoji[],
    shortcodes: Record<string, string | string[]>,
): EmojiEntry[] {
    return data.map((row) => ({
        emoji: row.emoji,
        label: row.label,
        hexcode: row.hexcode,
        tags: row.tags ?? [],
        shortcodes: toShortcodes(shortcodes[row.hexcode]),
        skins: (row.skins ?? [])
            .filter(
                (skin): skin is { hexcode: string; emoji: string; tone: number } =>
                    typeof skin.tone === 'number',
            )
            .map((skin) => ({ tone: skin.tone, emoji: skin.emoji })),
    }));
}

/** The base emoji rendered in the selected skin tone, or the base if unavailable. */
export function applySkinTone(entry: EmojiEntry, tone: EmojiSkinTone): string {
    const toneNumber = TONE_NUMBER[tone];
    if (toneNumber === 0) {
        return entry.emoji;
    }

    return entry.skins.find((skin) => skin.tone === toneNumber)?.emoji ?? entry.emoji;
}

/** Best match score for an entry against a lowercased needle (0 = no match). */
function scoreEntry(entry: EmojiEntry, needle: string): number {
    let best = 0;

    for (const shortcode of entry.shortcodes) {
        if (shortcode === needle) {
            return 4;
        }
        if (shortcode.startsWith(needle)) {
            best = Math.max(best, 3);
        } else if (shortcode.includes(needle)) {
            best = Math.max(best, 2);
        }
    }

    for (const token of [entry.label, ...entry.tags]) {
        if (token.toLowerCase().includes(needle)) {
            best = Math.max(best, 1);
        }
    }

    return best;
}

/** Rank emoji for the typeahead: shortcode hits first, then label/tag hits. */
export function rankEmoji(
    index: EmojiEntry[],
    query: string,
    opts: { skinTone: EmojiSkinTone; limit?: number },
): EmojiMatch[] {
    const needle = query.trim().toLowerCase();
    if (needle === '') {
        return [];
    }

    return index
        .map((entry) => ({ entry, score: scoreEntry(entry, needle) }))
        .filter(({ score }) => score > 0)
        .sort(
            (a, b) =>
                b.score - a.score ||
                (a.entry.shortcodes[0]?.length ?? 99) -
                    (b.entry.shortcodes[0]?.length ?? 99),
        )
        .slice(0, opts.limit ?? 8)
        .map(({ entry }) => ({
            emoji: applySkinTone(entry, opts.skinTone),
            label: entry.label,
            shortcode: entry.shortcodes[0] ?? entry.label,
        }));
}

let indexPromise: Promise<EmojiEntry[]> | null = null;

/**
 * Fetch and build the emoji index once (memoized). Data is self-hosted under
 * `${baseUrl}/${locale}/…` to satisfy the app CSP. Rejects on network failure;
 * callers must treat a rejection as "no matches" and never surface it to the editor.
 */
export function loadEmojiIndex(
    baseUrl = '/emoji',
    locale = 'en',
): Promise<EmojiEntry[]> {
    if (!indexPromise) {
        indexPromise = Promise.all([
            fetch(`${baseUrl}/${locale}/data.json`).then((r) => r.json()),
            fetch(`${baseUrl}/${locale}/shortcodes/emojibase.json`).then((r) =>
                r.json(),
            ),
        ])
            .then(([data, shortcodes]) => buildEmojiIndex(data, shortcodes))
            .catch((error) => {
                // Allow a later open to retry rather than caching the failure.
                indexPromise = null;
                throw error;
            });
    }

    return indexPromise;
}
