import { describe, expect, it } from 'vitest';

import BlueskyOAuthController from '@/actions/App/Http/Controllers/ConnectedAccounts/BlueskyOAuthController';
import OAuthConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/OAuthConnectionController';
import type { Account } from '@/components/accounts/types';
import { reconnectOAuthUrl } from '@/pages/accounts/index';

function account(overrides: Partial<Account>): Account {
    return {
        id: '1',
        platform: 'bluesky',
        platform_label: 'Bluesky',
        handle: '@me.bsky.social',
        display_name: null,
        avatar_url: null,
        status: 'needs_attention',
        status_label: 'Needs attention',
        auth_method: 'oauth',
        connected_by: null,
        token_expires_at: null,
        max_text_length: 300,
        x_premium: false,
        is_default: false,
        disabled: false,
        pds_url: null,
        ...overrides,
    };
}

describe('reconnectOAuthUrl', () => {
    it('uses the dedicated Bluesky OAuth route, prefilling the handle as identifier', () => {
        expect(
            reconnectOAuthUrl(
                account({ platform: 'bluesky', handle: '@me.bsky.social' }),
            ),
        ).toBe(
            BlueskyOAuthController.redirect.url({
                query: { identifier: 'me.bsky.social' },
            }),
        );
    });

    it('replays a saved custom PDS as pds_url for Bluesky accounts', () => {
        expect(
            reconnectOAuthUrl(
                account({
                    platform: 'bluesky',
                    handle: '@me.bsky.social',
                    pds_url: 'https://pds.example',
                }),
            ),
        ).toBe(
            BlueskyOAuthController.redirect.url({
                query: {
                    identifier: 'me.bsky.social',
                    pds_url: 'https://pds.example',
                },
            }),
        );
    });

    it('uses the generic OAuth route for other platforms', () => {
        expect(reconnectOAuthUrl(account({ platform: 'x' }))).toBe(
            OAuthConnectionController.redirect.url({ platform: 'x' }),
        );
    });
});
