import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = () =>
    readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/compose/media-chips.tsx',
        ),
        'utf8',
    );

describe('media chips', () => {
    it('marks bluesky gif attachments as video-published gifs', () => {
        const chips = source();

        expect(chips).toContain("activePlatform === 'bluesky'");
        expect(chips).toContain("m.mime === 'image/gif'");
        expect(chips).toContain('Bluesky will publish this GIF as video');
        expect(chips).toContain('<Film');
    });

    it('never marks a gif as editable (the beautifier would flatten it)', () => {
        const chips = source();

        // Image chips are editable only when the item is not a GIF.
        expect(chips).toContain('Boolean(onImageClick) && !isGif');
    });
});
