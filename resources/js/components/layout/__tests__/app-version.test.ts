import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { appVersion, githubReleaseUrl } from '@/lib/version';

describe('app version badge', () => {
    it('loads the current app version from the root VERSION file', () => {
        const versionFile = readFileSync(
            resolve(process.cwd(), 'VERSION'),
            'utf8',
        ).trim();

        expect(appVersion).toBe(versionFile);
        expect(appVersion).toMatch(/^v\d+\.\d+\.\d+/);
    });

    it('links the displayed version to the matching GitHub release', () => {
        expect(githubReleaseUrl).toBe(
            `https://github.com/coollabsio/shoutrrr/releases/tag/${appVersion}`,
        );
    });
});
