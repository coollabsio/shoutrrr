import { describe, expect, it } from 'vitest';

import { appVersion, githubReleaseUrl } from '@/lib/version';

describe('app version badge', () => {
    it('exposes the app version injected at build time', () => {
        // Injected via Vite `define` from the release tag (or `git describe`
        // locally). Always a string; may be empty in build contexts with no
        // tag and no git.
        expect(typeof appVersion).toBe('string');
    });

    it('links the displayed version to the matching GitHub release', () => {
        expect(githubReleaseUrl).toBe(
            `https://github.com/coollabsio/shoutrrr/releases/tag/${appVersion}`,
        );
    });
});
