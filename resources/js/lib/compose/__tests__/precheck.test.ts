import { describe, expect, it } from 'vitest';

import {
    describeReason,
    precheckAccount,
    precheckDestinations,
} from '@/lib/compose/precheck';
import type { Account, MediaView, PlatformLimits } from '@/types/compose';

function limitsFor(
    over: Partial<PlatformLimits> & { platform: PlatformLimits['platform'] },
): PlatformLimits {
    return {
        maxLength: 300,
        maxBytes: null,
        maxMedia: 4,
        requiresMedia: false,
        maxMediaBytes: 1_000_000,
        allowedMime: [],
        threadMax: null,
        maxImageDimensions: { width: 1, height: 1 },
        allowedVideoMime: [],
        maxVideoBytes: 1,
        maxVideoDurationSeconds: 1,
        ...over,
    };
}

function accountFor(
    over: Partial<Account> & { platform: Account['platform'] },
): Account {
    return {
        id: 'acc-1',
        handle: '@user',
        display_name: 'User',
        avatar_url: null,
        max_text_length: 0,
        x_premium: false,
        ...over,
    };
}

const NO_MEDIA: MediaView[] = [];

describe('precheckAccount', () => {
    it('flags a non-capped auto-split-off segment over the limit', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'bluesky' }),
            segments: ['x'.repeat(400)],
            autoSplit: false,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({
                platform: 'bluesky',
                maxLength: 300,
                maxBytes: 3000,
            }),
        });
        expect(reasons).toContain('section_too_long');
    });

    it('does NOT flag a long segment when auto-split is on (server hard-splits)', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'bluesky' }),
            segments: ['x'.repeat(400)],
            autoSplit: true,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({ platform: 'bluesky', maxLength: 300 }),
        });
        expect(reasons).not.toContain('section_too_long');
    });

    it('flags a thread-capped platform whose combined text is too long', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'linkedin' }),
            segments: ['a'.repeat(2000), 'b'.repeat(2000)],
            autoSplit: true,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({
                platform: 'linkedin',
                maxLength: 3000,
                threadMax: 1,
            }),
        });
        expect(reasons).toContain('section_too_long');
    });

    it('flags too many media', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'x' }),
            segments: ['hi'],
            autoSplit: true,
            mentions: [],
            mediaCount: 5,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({ platform: 'x', maxMedia: 4 }),
        });
        expect(reasons).toContain('too_many_media');
    });

    it('passes a within-limit post', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'x' }),
            segments: ['hello'],
            autoSplit: false,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({ platform: 'x', maxLength: 280 }),
        });
        expect(reasons).toEqual([]);
    });
});

function mediaItem(over: Partial<MediaView> & { id: string }): MediaView {
    return {
        url: 'https://example.test/m.jpg',
        mime: 'image/jpeg',
        kind: 'image',
        alt_text: null,
        duration_seconds: null,
        position: 0,
        edit_settings: null,
        source_url: null,
        ...over,
    };
}

describe('precheckDestinations', () => {
    it('returns one block per failing account with its handle', () => {
        const blocks = precheckDestinations({
            accounts: [
                accountFor({ id: 'a', platform: 'bluesky', handle: '@bsky' }),
                accountFor({ id: 'b', platform: 'x', handle: '@x' }),
            ],
            segments: ['y'.repeat(400)],
            mentions: [],
            autoSplitByAccount: { a: false, b: true },
            overrideByAccount: {},
            formatByAccount: {},
            media: NO_MEDIA,
            limits: [
                limitsFor({ platform: 'bluesky', maxLength: 300 }),
                limitsFor({ platform: 'x', maxLength: 280 }),
            ],
        });
        expect(blocks).toHaveLength(1);
        expect(blocks[0]).toMatchObject({ accountId: 'a', handle: '@bsky' });
    });

    it('counts the full media set for every target — a per-account media exclusion does not reduce the count', () => {
        // Five images over X's limit of 4. The composer may let a user "exclude"
        // one image from X, but the connector publishes the full post media set,
        // so the precheck must still block on the global count of 5.
        const media = [
            mediaItem({ id: 'm1' }),
            mediaItem({ id: 'm2' }),
            mediaItem({ id: 'm3' }),
            mediaItem({ id: 'm4' }),
            mediaItem({ id: 'm5' }),
        ];
        const blocks = precheckDestinations({
            accounts: [accountFor({ id: 'x1', platform: 'x', handle: '@x' })],
            segments: ['hi'],
            mentions: [],
            autoSplitByAccount: { x1: true },
            overrideByAccount: {},
            formatByAccount: {},
            media,
            limits: [limitsFor({ platform: 'x', maxMedia: 4 })],
        });
        expect(blocks).toHaveLength(1);
        expect(blocks[0].reasons).toContain('too_many_media');
    });
});

describe('precheckAccount empty content', () => {
    it('flags an account with no text and no media', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'x' }),
            segments: [],
            autoSplit: true,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({ platform: 'x', maxLength: 280 }),
        });
        expect(reasons).toEqual(['empty']);
    });

    it('flags an account whose segments are only whitespace', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'x' }),
            segments: ['   ', '\n'],
            autoSplit: true,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({ platform: 'x', maxLength: 280 }),
        });
        expect(reasons).toEqual(['empty']);
    });

    it('allows a media-only post with no text', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'x' }),
            segments: [],
            autoSplit: true,
            mentions: [],
            mediaCount: 1,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({ platform: 'x', maxLength: 280 }),
        });
        expect(reasons).toEqual([]);
    });

    it('does not flag empty on a thread-capped platform with text', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'linkedin' }),
            segments: ['hello'],
            autoSplit: false,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({ platform: 'linkedin', threadMax: 1 }),
        });
        expect(reasons).toEqual([]);
    });
});

describe('precheckAccount media-first platforms', () => {
    it('flags an Instagram caption with no media', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'instagram' }),
            segments: ['Test'],
            autoSplit: false,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({
                platform: 'instagram',
                requiresMedia: true,
                threadMax: 1,
            }),
        });
        expect(reasons).toEqual(['media_required']);
    });

    it('allows an Instagram caption with media', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'instagram' }),
            segments: ['Test'],
            autoSplit: false,
            mentions: [],
            mediaCount: 1,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({
                platform: 'instagram',
                requiresMedia: true,
                threadMax: 1,
            }),
        });
        expect(reasons).toEqual([]);
    });

    it('reports empty rather than media_required when there is no text either', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'instagram' }),
            segments: [],
            autoSplit: false,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({
                platform: 'instagram',
                requiresMedia: true,
                threadMax: 1,
            }),
        });
        expect(reasons).toEqual(['empty']);
    });

    it('does not flag a text-only post on a platform that allows it', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'x' }),
            segments: ['Test'],
            autoSplit: false,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'feed' as const,
            limits: limitsFor({ platform: 'x', maxLength: 280 }),
        });
        expect(reasons).toEqual([]);
    });
});

describe('format-aware precheck blocks', () => {
    it('blocks reels with no video', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'instagram' }),
            segments: ['hi'],
            autoSplit: true,
            mentions: [],
            mediaCount: 1,
            hasVideo: false,
            format: 'reels',
            limits: limitsFor({ platform: 'instagram', requiresMedia: true }),
        });
        expect(reasons).toContain('reels_requires_video');
    });

    it('does not block reels when a video is attached', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'instagram' }),
            segments: ['hi'],
            autoSplit: true,
            mentions: [],
            mediaCount: 1,
            hasVideo: true,
            format: 'reels',
            limits: limitsFor({ platform: 'instagram', requiresMedia: true }),
        });
        expect(reasons).not.toContain('reels_requires_video');
    });

    it('blocks a story with no media', () => {
        const reasons = precheckAccount({
            account: accountFor({ platform: 'instagram' }),
            segments: ['hi'],
            autoSplit: true,
            mentions: [],
            mediaCount: 0,
            hasVideo: false,
            format: 'story',
            limits: limitsFor({ platform: 'instagram', requiresMedia: true }),
        });
        expect(reasons).toContain('story_requires_media');
    });
});

describe('describeReason', () => {
    it('describes a media-first platform needing an attachment', () => {
        const text = describeReason(
            'media_required',
            'instagram',
            limitsFor({ platform: 'instagram', requiresMedia: true }),
        );
        expect(text).toContain('Instagram');
        expect(text).toContain('image or video');
    });

    it('describes empty content without a platform limit', () => {
        const text = describeReason(
            'empty',
            'x',
            limitsFor({ platform: 'x', maxLength: 280 }),
        );
        expect(text).toContain('text or media');
    });

    it('describes a video that is too long', () => {
        const text = describeReason(
            'video_too_long',
            'x',
            limitsFor({ platform: 'x', maxVideoDurationSeconds: 140 }),
        );
        expect(text).toContain('140s');
    });

    it('describes a video that is too large', () => {
        const text = describeReason(
            'video_too_large',
            'bluesky',
            limitsFor({
                platform: 'bluesky',
                maxVideoBytes: 100 * 1024 * 1024,
            }),
        );
        expect(text).toContain('100 MB');
    });

    it('describes mixing a video with images', () => {
        const text = describeReason(
            'mixed_video_and_images',
            'x',
            limitsFor({ platform: 'x' }),
        );
        expect(text).toContain('one video or images');
    });

    it('describes a GIF that cannot be mixed', () => {
        const text = describeReason(
            'gif_not_mixable',
            'x',
            limitsFor({ platform: 'x' }),
        );
        expect(text).toContain('GIF');
    });

    it('describes an over-length non-capped platform with the auto-split hint', () => {
        const text = describeReason(
            'section_too_long',
            'bluesky',
            limitsFor({ platform: 'bluesky', maxLength: 300 }),
        );
        expect(text).toContain('Bluesky');
        expect(text).toContain('300');
        expect(text).toContain('auto-split');
    });

    it('describes too many media', () => {
        const text = describeReason(
            'too_many_media',
            'x',
            limitsFor({ platform: 'x', maxMedia: 4 }),
        );
        expect(text).toContain('4 media');
    });
});
