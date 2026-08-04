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
    edit_url: 'https://app.example.test/media/media-1/raw',
    source_edit_url: null,
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

    it('distributes placed media under the thread post it belongs to', () => {
        const image2: MediaView = { ...image, id: 'media-2' };
        const preview = buildPlatformPreview({
            account: account('bluesky'),
            segments: ['First post', 'Second post'],
            mentions: [],
            media: [image, image2],
            excludedMediaIds: new Set(),
            limit: 300,
            autoSplit: true,
            // media-1 on the first segment (__head__), media-2 on the second (b0).
            placements: { __head__: ['media-1'], b0: ['media-2'] },
            segmentBreaks: ['b0'],
        });

        expect(preview.items.map((item) => item.text)).toEqual([
            'First post',
            'Second post',
        ]);
        expect(preview.items[0]?.media.map((m) => m.id)).toEqual(['media-1']);
        expect(preview.items[1]?.media.map((m) => m.id)).toEqual(['media-2']);
    });

    it('rides placed media on the first sub-post when a segment auto-splits', () => {
        const long = 'word '.repeat(40).trim(); // > 28 chars → splits on Bluesky-28
        const preview = buildPlatformPreview({
            account: account('bluesky'),
            segments: [long, 'tail'],
            mentions: [],
            media: [image],
            excludedMediaIds: new Set(),
            limit: 28,
            autoSplit: true,
            // Placed on the second authored segment (b0).
            placements: { b0: ['media-1'] },
            segmentBreaks: ['b0'],
        });

        // The first authored segment produced several sections (none carry media);
        // the media lands on the first section of the second authored segment.
        const withMedia = preview.items.filter((i) => i.media.length > 0);
        expect(withMedia).toHaveLength(1);
        expect(withMedia[0]?.text).toBe('tail');
        expect(withMedia[0]?.media.map((m) => m.id)).toEqual(['media-1']);
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
                linkExclusions: ['Actual Person'],
            },
        ]);
    });

    it('collapses extra blank lines to one in the X preview text but counts the raw body', () => {
        const preview = buildPlatformPreview({
            account: { ...account('x'), max_text_length: 280 },
            segments: ['line one\n\n\n\nline two'],
            mentions: [],
            media: [],
            excludedMediaIds: new Set(),
            limit: 280,
            autoSplit: true,
        });

        // Rendered spacing matches X (one blank line), while the count still
        // reflects the four transmitted newlines.
        expect(preview.items[0]?.text).toBe('line one\n\nline two');
        expect(preview.items[0]?.count).toBe('line one\n\n\n\nline two'.length);
    });

    it('keeps every blank line in the Bluesky preview text', () => {
        const preview = buildPlatformPreview({
            account: { ...account('bluesky'), max_text_length: 300 },
            segments: ['line one\n\n\n\nline two'],
            mentions: [],
            media: [],
            excludedMediaIds: new Set(),
            limit: 300,
            autoSplit: false,
        });

        expect(preview.items[0]?.text).toBe('line one\n\n\n\nline two');
    });

    it('marks LinkedIn mention display domains as link exclusions', () => {
        const preview = buildPlatformPreview({
            account: account('linkedin'),
            segments: ['hello shoutrrr.com @Person'],
            mentions: [
                {
                    id: 'person',
                    label: '@Person',
                    handles: { linkedin: 'heyandras.dev' },
                },
            ],
            media: [],
            excludedMediaIds: new Set(),
            limit: 3000,
            autoSplit: true,
        });

        expect(preview.items[0]).toMatchObject({
            text: 'hello shoutrrr.com heyandras.dev',
            linkExclusions: ['heyandras.dev'],
        });
    });
});
