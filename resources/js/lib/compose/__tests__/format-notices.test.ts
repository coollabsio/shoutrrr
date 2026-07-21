import { describe, expect, it } from 'vitest';

import {
    describeFormatNotice,
    formatNoticesForAccount,
} from '@/lib/compose/format-notices';

describe('format notices', () => {
    it('warns that a story with text drops the caption', () => {
        expect(
            formatNoticesForAccount({
                platform: 'instagram',
                format: 'story',
                hasText: true,
                mediaCount: 1,
            }),
        ).toEqual(['caption_dropped']);
    });

    it('does not warn about captions for reels or feed', () => {
        expect(
            formatNoticesForAccount({
                platform: 'instagram',
                format: 'reels',
                hasText: true,
                mediaCount: 1,
            }),
        ).toEqual([]);
        expect(
            formatNoticesForAccount({
                platform: 'facebook',
                format: 'feed',
                hasText: true,
                mediaCount: 1,
            }),
        ).toEqual([]);
    });

    it('warns when a story has more than one media item', () => {
        expect(
            formatNoticesForAccount({
                platform: 'facebook',
                format: 'story',
                hasText: false,
                mediaCount: 3,
            }),
        ).toEqual(['story_first_media_only']);
    });

    it('describes the caption-dropped notice with the platform label', () => {
        expect(describeFormatNotice('caption_dropped', 'instagram')).toBe(
            "Stories don't support captions — your text won't be posted to Instagram. It'll still post everywhere else.",
        );
    });
});
