import type { EmojiSkinTone } from './types';

export const MAX_RECENTS = 16;
export const RECENTS_KEY = 'shoutrrr:emoji:recents';
export const SKIN_TONE_KEY = 'shoutrrr:emoji:skin-tone';

const SKIN_TONES: EmojiSkinTone[] = [
    'none',
    'light',
    'medium-light',
    'medium',
    'medium-dark',
    'dark',
];

/** Most-recently-used list: dedupe to front, cap at `max`. */
export function pushRecent(
    list: string[],
    emoji: string,
    max: number = MAX_RECENTS,
): string[] {
    return [emoji, ...list.filter((item) => item !== emoji)].slice(0, max);
}

/** Parse a stored recents list, tolerating null/corrupt/wrong-typed values. */
export function parseRecents(raw: string | null): string[] {
    if (!raw) {
        return [];
    }

    try {
        const parsed: unknown = JSON.parse(raw);
        if (
            Array.isArray(parsed) &&
            parsed.every((item) => typeof item === 'string')
        ) {
            return parsed;
        }
    } catch {
        // fall through
    }

    return [];
}

/** Parse a stored skin tone, falling back to 'none'. */
export function parseSkinTone(raw: string | null): EmojiSkinTone {
    return SKIN_TONES.includes(raw as EmojiSkinTone)
        ? (raw as EmojiSkinTone)
        : 'none';
}
