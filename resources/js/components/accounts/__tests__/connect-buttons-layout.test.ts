import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    COLLAPSIBLE_TRIGGER_ICON_CLASS,
    isSupportedPlatformIcon,
    metaConnectLabel,
} from '../connect-buttons';
import type { Capability } from '../types';

function capability(overrides: Partial<Capability>): Capability {
    return {
        platform: 'facebook',
        label: 'Facebook',
        supportsOAuth: true,
        supportsAppPassword: false,
        configured: true,
        launched: false,
        enabled: true,
        ...overrides,
    };
}

describe('Bluesky connect dialog layout', () => {
    it('marks the collapsible trigger icon as a visible expandable control', () => {
        expect(COLLAPSIBLE_TRIGGER_ICON_CLASS).toContain(
            '[&[data-panel-open]_svg]:rotate-180',
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

    it('lets Bluesky OAuth start without a handle and submit a service URL', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/connect-buttons.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('leave it blank to choose on');
        expect(source).toContain('name="pds_url"');
        expect(source).toContain('Choose Bluesky instance');
        expect(source).not.toContain(`id="oauth_identifier"
                                name="identifier"
                                placeholder="you.bsky.social"
                                required`);
    });

    it('lets app-password Bluesky connections submit a service URL', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/connect-buttons.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('appPasswordServiceOpen');
        expect(source).toContain('id="app_password_pds_url"');
        expect(source.match(/name="pds_url"/g)).toHaveLength(2);
    });

    it('adds breathing room above Bluesky app password actions', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/connect-buttons.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('<DialogFooter className="pt-4">');
    });
});

describe('Meta connect entry point', () => {
    it('folds Facebook and Instagram into a single button', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/connect-buttons.tsx',
            ),
            'utf8',
        );

        // Instagram is filtered out as its own row — it connects through the
        // Facebook (Meta) entry, which points at the Meta Page-selection flow.
        expect(source).toContain("c.platform !== 'instagram'");
        expect(source).toContain("capability.platform === 'facebook'");
        expect(source).toContain('MetaConnectionController.redirect.url()');
    });

    it('labels the button Facebook-only until Instagram launches', () => {
        expect(
            metaConnectLabel([
                capability({ platform: 'facebook', launched: false }),
                capability({ platform: 'instagram', launched: false }),
            ]),
        ).toBe('Facebook');
    });

    it('mentions Instagram once it is launched', () => {
        expect(
            metaConnectLabel([
                capability({ platform: 'facebook', launched: false }),
                capability({ platform: 'instagram', launched: true }),
            ]),
        ).toBe('Facebook / Instagram');
    });
});
