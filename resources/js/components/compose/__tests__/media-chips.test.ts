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
});
