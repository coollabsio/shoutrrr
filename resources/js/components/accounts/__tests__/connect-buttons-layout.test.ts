import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    COLLAPSIBLE_TRIGGER_ICON_CLASS,
    isSupportedPlatformIcon,
} from '../connect-buttons';

describe('Bluesky connect dialog layout', () => {
    it('marks the collapsible trigger icon as a visible expandable control', () => {
        expect(COLLAPSIBLE_TRIGGER_ICON_CLASS).toContain(
            '[&[data-state=open]_svg]:rotate-180',
        );
    });

    it('uses real platform glyphs for supported connect buttons', () => {
        for (const platform of ['x', 'bluesky', 'linkedin']) {
            expect(isSupportedPlatformIcon(platform)).toBe(true);
        }

        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/connect-buttons.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('PlatformGlyph');
        expect(source).not.toContain('BriefcaseBusiness');
        expect(source).not.toContain('X as XIcon');
    });

    it('shows the at sign as a non-submitted Bluesky handle prefix', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/connect-buttons.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('InputGroupAddon');
        expect(source).toContain("'@'");
        expect(source).toContain('InputGroupInput');
    });
});
