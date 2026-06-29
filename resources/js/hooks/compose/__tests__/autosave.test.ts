import { describe, expect, it } from 'vitest';

import { AUTOSAVE_DEBOUNCE_MS } from '../use-autosave';

describe('autosave debounce', () => {
    it('waits 500ms after draft edits before saving', () => {
        expect(AUTOSAVE_DEBOUNCE_MS).toBe(500);
    });
});
