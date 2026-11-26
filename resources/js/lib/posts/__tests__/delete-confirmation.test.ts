import { describe, expect, it } from 'vitest';

import { deleteConfirmationDescription } from '@/lib/posts/delete-confirmation';
import type { TikTokOptions } from '@/types/compose';

const inboxOptions: TikTokOptions = {
    post_mode: 'inbox',
    privacy_level: null,
    allow_comment: false,
    allow_duet: false,
    allow_stitch: false,
    disclose_commercial: false,
    brand_organic: false,
    branded_content: false,
};

describe('deleteConfirmationDescription', () => {
    it('says unpublished content is not kept', () => {
        expect(
            deleteConfirmationDescription({ status: 'draft', targets: [] }),
        ).toBe('This removes the post. The content is not kept.');
    });

    it('promises remote removal where the platforms allow it', () => {
        expect(
            deleteConfirmationDescription({
                status: 'published',
                targets: [{ platform: 'x', status: 'published' }],
            }),
        ).toBe(
            'Published copies will be removed from connected accounts where possible.',
        );
    });

    it('warns that a published TikTok post stays live', () => {
        const description = deleteConfirmationDescription({
            status: 'published',
            targets: [
                { platform: 'x', status: 'published' },
                { platform: 'tiktok', status: 'published' },
            ],
        });

        expect(description).toContain(
            "It stays live on TikTok, which can't delete posts through its API",
        );
    });

    it('says a draft sent to the TikTok inbox stays there, not that it is live', () => {
        const description = deleteConfirmationDescription({
            status: 'published',
            targets: [
                {
                    platform: 'tiktok',
                    status: 'published',
                    tiktok: { ...inboxOptions },
                },
            ],
        });

        expect(description).not.toContain('stays live');
        expect(description).toContain(
            'The draft sent to the TikTok inbox is not removed: delete it in the TikTok app, and the post too if it was published from there.',
        );
    });

    it('names both a live TikTok post and an inbox draft', () => {
        const description = deleteConfirmationDescription({
            status: 'published',
            targets: [
                { platform: 'tiktok', status: 'published', tiktok: null },
                {
                    platform: 'tiktok',
                    status: 'published',
                    tiktok: { ...inboxOptions },
                },
            ],
        });

        expect(description).toContain('It stays live on TikTok');
        expect(description).toContain(
            'The draft sent to the TikTok inbox is not removed',
        );
    });

    it('does not warn about a TikTok target that never published', () => {
        expect(
            deleteConfirmationDescription({
                status: 'failed',
                targets: [{ platform: 'tiktok', status: 'failed' }],
            }),
        ).not.toContain('stays live');
    });
});
