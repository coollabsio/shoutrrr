import { describe, expect, it } from 'vitest';

import type { Account, MediaView, MentionPlaceholder } from '@/types/compose';

import { buildXPreview } from '../x-preview';

const account: Account = {
    id: 'x-1',
    platform: 'x',
    handle: '@shoutrrr',
    display_name: 'Shoutrrr',
    avatar_url: 'https://example.test/avatar.png',
    max_text_length: 28,
    x_premium: false,
};

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
        handles: { x: '@actual_person', linkedin: 'Person' },
    },
];

describe('buildXPreview', () => {
    it('resolves X mention handles and auto-splits draft text into thread items', () => {
        const preview = buildXPreview({
            account,
            segments: [
                'Launch with @Person\nSecond short paragraph\nThird short paragraph',
            ],
            mentions,
            media: [image],
            excludedMediaIds: new Set(),
            limit: 28,
            autoSplit: true,
        });

        expect(preview.accountHandle).toBe('@shoutrrr');
        expect(preview.items.map((item) => item.text)).toEqual([
            'Launch with @actual_person',
            'Second short paragraph',
            'Third short paragraph',
        ]);
        expect(preview.items[0]?.media).toEqual([image]);
        expect(preview.items[1]?.media).toEqual([]);
    });

    it('keeps one item when auto-split is disabled and filters media excluded for the account', () => {
        const preview = buildXPreview({
            account,
            segments: ['One paragraph\nAnother paragraph'],
            mentions: [],
            media: [image],
            excludedMediaIds: new Set(['media-1']),
            limit: 10,
            autoSplit: false,
        });

        expect(preview.items).toEqual([
            {
                id: 'x-preview-1',
                text: 'One paragraph\nAnother paragraph',
                media: [],
                count: 31,
                overLimit: true,
            },
        ]);
    });
});

it('treats a literal --- as post text, not a thread boundary', () => {
    const preview = buildXPreview({
        account,
        segments: ['First post\n---\nSecond post'],
        mentions: [],
        media: [],
        excludedMediaIds: new Set(),
        limit: 28,
        autoSplit: true,
    });

    expect(preview.items.map((item) => item.text)).toEqual([
        'First post\n---\nSecond post',
    ]);
});
