import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('OAuth account reconnect route selection', () => {
    it('uses the dedicated Bluesky OAuth route for Bluesky accounts', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/pages/accounts/index.tsx'),
            'utf8',
        );

        expect(source).toContain('BlueskyOAuthController');
        expect(source).toContain("account.platform === 'bluesky'");
        expect(source).toContain('BlueskyOAuthController.redirect.url()');
    });
});
