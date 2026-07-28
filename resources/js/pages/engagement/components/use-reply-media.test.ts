/** @vitest-environment jsdom */

import { useHttp } from '@inertiajs/react';
import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { MediaView } from '@/types/compose';
import type { GifItem } from '@/types/gifs';

import { useReplyMedia } from './use-reply-media';

// The reply endpoint has no server-side "media already on this reply" query
// (post_media has no reply_id column), so ReplyGifController relies entirely
// on a client-declared media_ids array to run its mixing-rule guard. This test
// pins that the request body actually carries it — see AttachGifRequest and
// ReplyGifController::store()'s $existing query.
vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
    usePage: vi.fn(() => ({ props: { shell: { limits: [] } } })),
}));

vi.mock(
    '@/actions/App/Http/Controllers/Engagement/ReplyImageEditController',
    () => ({
        default: {
            store: (id: string) => ({ url: `/engagement/${id}/image-edit` }),
            update: ({ reply }: { reply: string; media: string }) => ({
                url: `/engagement/${reply}/image-edit`,
            }),
        },
    }),
);

vi.mock(
    '@/actions/App/Http/Controllers/Engagement/ReplyMediaController',
    () => ({
        default: {
            store: (id: string) => ({ url: `/engagement/${id}/media` }),
        },
    }),
);

vi.mock(
    '@/actions/App/Http/Controllers/Engagement/ReplyVideoUploadController',
    () => ({
        default: {
            url: (id: string) => ({ url: `/engagement/${id}/video/sign` }),
            store: (id: string) => ({ url: `/engagement/${id}/video` }),
        },
    }),
);

vi.mock('@/actions/App/Http/Controllers/Gifs/ReplyGifController', () => ({
    default: {
        store: {
            url: ({ reply }: { reply: string }) => `/engagement/${reply}/gifs`,
        },
    },
}));

vi.mock('@/components/compose/image-editor', () => ({
    ImageEditor: () => null,
}));

vi.mock('@/components/compose/media-chips', () => ({
    MediaChips: () => null,
}));

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

const transform = vi.fn();

let root: Root | null = null;
let container: HTMLDivElement | null = null;
let attachGifRef: ((item: GifItem) => Promise<void>) | null = null;

function existingMedia(id: string): MediaView {
    return {
        id,
        url: `https://cdn.test/${id}.png`,
        mime: 'image/png',
        kind: 'image',
        alt_text: null,
        duration_seconds: null,
        position: 0,
        edit_settings: null,
        source_url: null,
        edit_url: `/media/${id}/edit`,
        source_edit_url: null,
    };
}

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

function Harness({ media }: { media: MediaView[] }) {
    const rm = useReplyMedia({
        replyId: 'reply-1',
        platform: 'bluesky',
        media,
        onChange: () => {},
    });
    attachGifRef = rm.attachGif;

    return null;
}

beforeEach(() => {
    transform.mockReset();
    vi.mocked(useHttp).mockReturnValue({
        transform,
        post: vi.fn(),
        processing: false,
    } as unknown as ReturnType<typeof useHttp>);
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async () =>
                new Response(
                    JSON.stringify({ media: existingMedia('new-media') }),
                    { status: 201 },
                ),
        ),
    );
    container = document.createElement('div');
    root = createRoot(container);
});

afterEach(() => {
    act(() => root?.unmount());
    root = null;
    container = null;
    attachGifRef = null;
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useReplyMedia attachGif', () => {
    it("sends media_ids populated from the reply's current media", async () => {
        act(() => {
            root?.render(
                createElement(Harness, {
                    media: [existingMedia('m1'), existingMedia('m2')],
                }),
            );
        });

        await act(async () => {
            await attachGifRef?.(gifItem());
        });

        expect(globalThis.fetch).toHaveBeenCalledOnce();
        const [, init] = vi.mocked(globalThis.fetch).mock.calls[0];
        const body = JSON.parse((init as RequestInit).body as string) as {
            media_ids: string[];
        };
        expect(body.media_ids).toEqual(['m1', 'm2']);
    });

    it("surfaces the server's message when the attach fails", async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(
                        JSON.stringify({
                            message:
                                'You cannot mix images and video on one post.',
                        }),
                        { status: 422 },
                    ),
            ),
        );

        act(() => {
            root?.render(createElement(Harness, { media: [] }));
        });

        await act(async () => {
            await attachGifRef?.(gifItem());
        });

        // trackPending swallows the rejection into a toast + error chip, so the
        // failure surfaces through the toast rather than a thrown error here.
        expect(toast.error).toHaveBeenCalledWith(
            'You cannot mix images and video on one post.',
        );
    });

    it('falls back to shared copy when the failure carries no message', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response('nope', { status: 500 })),
        );

        act(() => {
            root?.render(createElement(Harness, { media: [] }));
        });

        await act(async () => {
            await attachGifRef?.(gifItem());
        });

        expect(toast.error).toHaveBeenCalledWith(
            'That GIF could not be attached.',
        );
    });

    it('sends an empty media_ids array when the reply has no media yet', async () => {
        act(() => {
            root?.render(createElement(Harness, { media: [] }));
        });

        await act(async () => {
            await attachGifRef?.(gifItem());
        });

        const [, init] = vi.mocked(globalThis.fetch).mock.calls[0];
        const body = JSON.parse((init as RequestInit).body as string) as {
            media_ids: string[];
        };
        expect(body.media_ids).toEqual([]);
    });
});
