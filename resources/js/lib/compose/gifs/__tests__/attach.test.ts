import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { GifItem } from '@/types/gifs';

import { postGifAttachment } from '../attach';

vi.mock('@/lib/csrf', () => ({
    xsrfHeader: () => ({ 'X-XSRF-TOKEN': 'test-token' }),
}));

function gifItem(): GifItem {
    return {
        id: 'g1',
        slug: 'happy-dance',
        catalog: 'gif',
        title: 'Happy dance',
        preview: {
            url: 'https://static.klipy.com/happy-dance.gif',
            mime: 'image/gif',
            width: 320,
            height: 240,
            bytes: 40000,
        },
        variants: [
            {
                url: 'https://static.klipy.com/happy-dance.gif',
                mime: 'image/gif',
                width: 320,
                height: 240,
                bytes: 40000,
            },
        ],
    };
}

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => vi.unstubAllGlobals());

describe('postGifAttachment', () => {
    it('posts the item and declared media ids, and returns the created media', async () => {
        vi.mocked(globalThis.fetch).mockResolvedValue(
            new Response(JSON.stringify({ media: { id: 'm9' } }), {
                status: 201,
            }),
        );

        const media = await postGifAttachment('/posts/p1/gifs', gifItem(), [
            'm1',
            'm2',
        ]);

        expect(media.id).toBe('m9');

        const [url, init] = vi.mocked(globalThis.fetch).mock.calls[0];
        expect(url).toBe('/posts/p1/gifs');
        expect((init as RequestInit).method).toBe('POST');
        expect((init as RequestInit).headers).toMatchObject({
            'X-XSRF-TOKEN': 'test-token',
        });
        expect(JSON.parse((init as RequestInit).body as string)).toMatchObject({
            catalog: 'gif',
            slug: 'happy-dance',
            media_ids: ['m1', 'm2'],
        });
    });

    it("throws the server's message when the attach is rejected", async () => {
        vi.mocked(globalThis.fetch).mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'You cannot mix images and video on one post.',
                }),
                { status: 422 },
            ),
        );

        await expect(
            postGifAttachment('/posts/p1/gifs', gifItem(), []),
        ).rejects.toThrow('You cannot mix images and video on one post.');
    });

    it('falls back to shared copy when the failure body carries no message', async () => {
        vi.mocked(globalThis.fetch).mockResolvedValue(
            new Response('nope', { status: 500 }),
        );

        await expect(
            postGifAttachment('/posts/p1/gifs', gifItem(), []),
        ).rejects.toThrow('That GIF could not be attached.');
    });
});
