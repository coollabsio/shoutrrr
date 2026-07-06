import { useEffect, useState } from 'react';

import {
    parseRecents,
    parseSkinTone,
    pushRecent,
    RECENTS_KEY,
    SKIN_TONE_KEY,
} from '@/lib/compose/emoji/preferences';
import type { EmojiSkinTone } from '@/lib/compose/emoji/types';

export type EmojiPreferences = {
    recents: string[];
    addRecent: (emoji: string) => void;
    skinTone: EmojiSkinTone;
    setSkinTone: (tone: EmojiSkinTone) => void;
};

/** localStorage-backed emoji preferences (per browser): recents + skin tone. */
export function useEmojiPreferences(): EmojiPreferences {
    const [recents, setRecents] = useState<string[]>([]);
    const [skinTone, setSkinToneState] = useState<EmojiSkinTone>('none');

    // Hydrate once on mount (SSR-safe: localStorage is unavailable server-side).
    useEffect(() => {
        setRecents(parseRecents(localStorage.getItem(RECENTS_KEY)));
        setSkinToneState(parseSkinTone(localStorage.getItem(SKIN_TONE_KEY)));
    }, []);

    function addRecent(emoji: string): void {
        setRecents((current) => {
            const next = pushRecent(current, emoji);
            localStorage.setItem(RECENTS_KEY, JSON.stringify(next));

            return next;
        });
    }

    function setSkinTone(tone: EmojiSkinTone): void {
        setSkinToneState(tone);
        localStorage.setItem(SKIN_TONE_KEY, tone);
    }

    return { recents, addRecent, skinTone, setSkinTone };
}
