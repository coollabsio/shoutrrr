import { describe, expect, it } from 'vitest';

import { postPermalink } from '../permalink';

describe('postPermalink', () => {
    it('links a public TikTok post by its post id', () => {
        expect(postPermalink('tiktok', '@clips', '7710962537105247510')).toBe(
            'https://www.tiktok.com/@clips/video/7710962537105247510',
        );
    });

    it('has no link for a TikTok publish id, which a private post or inbox draft keeps', () => {
        expect(
            postPermalink('tiktok', '@clips', 'v_pub_url~v2-1.7710962537'),
        ).toBeNull();
    });

    it('has no TikTok link without a handle', () => {
        expect(postPermalink('tiktok', null, '7710962537105247510')).toBeNull();
    });
});
