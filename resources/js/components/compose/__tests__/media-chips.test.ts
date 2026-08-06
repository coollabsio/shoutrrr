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

    it('never marks an animated image as editable (the beautifier would flatten it)', () => {
        const chips = source();

        // Animated images (GIF, or a GIF-browser WebP) are attach-only, decided by
        // the shared isAttachOnlyImage() rule (mime + edit_settings).
        expect(chips).toContain(
            'Boolean(onImageClick) && !isAttachOnlyImage(m)',
        );
    });

    it("CornerButton's always-visible variant does not override display (would break the icon's centering against the base grid layout)", () => {
        const chips = source();

        expect(chips).not.toMatch(/always\s*\?\s*'flex'/);
        expect(chips).toContain('!always &&');
    });
});
