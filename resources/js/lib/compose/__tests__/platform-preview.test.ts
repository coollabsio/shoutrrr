import { describe, expect, it } from 'vitest';

import type {
    Account,
    MediaView,
    MentionPlaceholder,
    PlatformName,
} from '@/types/compose';

import { buildPlatformPreview } from '../platform-preview';

function account(platform: PlatformName): Account {
    return {
        id: `${platform}-1`,
        platform,
        handle:
            platform === 'linkedin'
                ? 'shoutrrr'
                : platform === 'bluesky'
                  ? '@shoutrrr.bsky.social'
                  : '@shoutrrr',
        display_name: 'Shoutrrr',
        avatar_url: 'https://example.test/avatar.png',
        max_text_length: platform === 'linkedin' ? 3000 : 28,
        x_premium: false,
    };
}

const image: MediaView = {
    id: 'media-1',
    url: 'https://example.test/image.jpg',
    mime: 'image/jpeg',
    kind: 'image',
    alt_text: null,
    duration_seconds: null,
    position: 0,
    edit_settings: null,
    source_url: null,
};

const mentions: MentionPlaceholder[] = [
    {
        id: 'person',
        label: '@Person',
        handles: {
            x: '@actual_person',
            bluesky: '@actual-person.bsky.social',
            linkedin: 'Actual Person',
        },
    },
];

describe('buildPlatformPreview', () => {
    it('builds a Bluesky thread preview using Bluesky mention handles', () => {
        const preview = buildPlatformPreview({
            account: account('bluesky'),
            segments: ['Launch with @Person\nSecond short paragraph'],
            mentions,
            media: [image],
            excludedMediaIds: new Set(),
            limit: 28,
            autoSplit: true,
        });

        expect(preview.platform).toBe('bluesky');
        expect(preview.items.map((item) => item.text)).toEqual([
            'Launch with @actual-person.bsky.social',
            'Second short paragraph',
        ]);
        expect(preview.items[0]?.media).toEqual([image]);
    });

    it('honors manual segment split when automatic splitting is off', () => {
        const preview = buildPlatformPreview({
            account: account('bluesky'),
            segments: ['One', 'Two'],
            mentions: [],
            media: [],
            excludedMediaIds: new Set(),
            limit: 300,
            autoSplit: false,
        });

        expect(preview.items.map((item) => item.text)).toEqual(['One', 'Two']);
    });

    it('builds a single LinkedIn update with LinkedIn mention display text', () => {
        const preview = buildPlatformPreview({
            account: account('linkedin'),
            segments: ['Launch with @Person', 'Second paragraph'],
            mentions,
            media: [image],
            excludedMediaIds: new Set(['media-1']),
            limit: 3000,
            autoSplit: true,
        });

        expect(preview.platform).toBe('linkedin');
        expect(preview.items).toEqual([
            {
                id: 'linkedin-preview-1',
                text: 'Launch with Actual Person\nSecond paragraph',
                media: [],
                count: 42,
                overLimit: false,
            },
        ]);
    });
});
