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

    // Hydrate once on mount. Guarded because localStorage access throws in
    // some environments (private mode, disabled storage) as well as SSR.
    useEffect(() => {
        try {
            setRecents(parseRecents(localStorage.getItem(RECENTS_KEY)));
            setSkinToneState(
                parseSkinTone(localStorage.getItem(SKIN_TONE_KEY)),
            );
        } catch {
            // Keep the in-memory defaults when storage is unavailable.
        }
    }, []);

    function addRecent(emoji: string): void {
        setRecents((current) => {
            const next = pushRecent(current, emoji);
            persist(RECENTS_KEY, JSON.stringify(next));

            return next;
        });
    }

    function setSkinTone(tone: EmojiSkinTone): void {
        setSkinToneState(tone);
        persist(SKIN_TONE_KEY, tone);
    }

    return { recents, addRecent, skinTone, setSkinTone };
}

/** Write to localStorage, swallowing errors (quota/private-mode/disabled). */
function persist(key: string, value: string): void {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Preference just won't persist this session; not worth surfacing.
    }
}
