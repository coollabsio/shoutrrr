import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    ADVANCED_SERVICE_URL_TRIGGER_CLASS,
    isSupportedPlatformIcon,
} from '../connect-buttons';

describe('Bluesky connect dialog layout', () => {
    it('marks the advanced service URL trigger as a visible expandable control', () => {
        expect(ADVANCED_SERVICE_URL_TRIGGER_CLASS).toContain(
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
        expect(source).toContain('<InputGroupAddon>@</InputGroupAddon>');
        expect(source).toContain('InputGroupInput');
    });
});
