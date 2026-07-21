import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { ACCOUNT_CARD_ACTIONS_CLASS } from '../account-card';

describe('account card layout', () => {
    it('lets management actions wrap inside the card', () => {
        expect(ACCOUNT_CARD_ACTIONS_CLASS).toContain('flex-wrap');
    });

    it('links Bluesky reconnect users to app passwords', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/account-card.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('https://bsky.app/settings/app-passwords');
        expect(source).toContain('app password');
    });

    it('shows the at sign as a non-submitted Bluesky reconnect handle prefix', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/account-card.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('InputGroupAddon');
        expect(source).toContain('<InputGroupAddon>@</InputGroupAddon>');
        expect(source).toContain('InputGroupInput');
    });

    it('reconnects webhook accounts through a Discord webhook-URL dialog, not the OAuth redirect', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/account-card.tsx',
            ),
            'utf8',
        );

        expect(source).toContain("account.auth_method === 'webhook'");
        expect(source).toContain('ReconnectDiscordDialog');
        expect(source).toContain('name="webhook_url"');
    });
});
