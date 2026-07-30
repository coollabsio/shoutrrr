/** @vitest-environment jsdom */

import { useHttp } from '@inertiajs/react';
import { act, createElement, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { MediaView } from '@/types/compose';
import type { GifItem } from '@/types/gifs';

import { useAttachments } from '../use-attachments';

// The GIF endpoints have no server-side "media already on this record" query
// (post_media has no reply_id/conversation_id column), so the controller relies
// entirely on a client-declared media_ids array to run its mixing-rule guard.
// This test pins that the request body actually carries it — see
// AttachGifRequest and ReplyGifController::store()'s $existing query.
vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
    usePage: vi.fn(() => ({ props: { shell: { limits: [] } } })),
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
const post = vi.fn();

let root: Root | null = null;
let container: HTMLDivElement | null = null;
let attachGifRef: ((item: GifItem) => Promise<void>) | null = null;
let addFilesRef: ((files: File[]) => Promise<void>) | null = null;
let editorRef: ReactNode = null;
let chipsRef: ReactNode = null;

const endpoints = {
    imageStore: (id: string) => `/engagement/${id}/media`,
    videoSign: (id: string) => `/engagement/${id}/video/sign`,
    videoStore: (id: string) => `/engagement/${id}/video`,
    gifStore: (id: string) => `/engagement/${id}/gifs`,
    imageEdit: {
        store: (id: string) => `/engagement/${id}/image-edit`,
        update: ({ owner }: { owner: string; media: string }) =>
            `/engagement/${owner}/image-edit`,
    },
};

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

function Harness({
    media,
    withImageEdit = true,
}: {
    media: MediaView[];
    withImageEdit?: boolean;
}) {
    const rm = useAttachments({
        ownerId: 'reply-1',
        platform: 'bluesky',
        media,
        onChange: () => {},
        endpoints: withImageEdit
            ? endpoints
            : { ...endpoints, imageEdit: undefined },
    });
    attachGifRef = rm.attachGif;
    addFilesRef = rm.handleAddedFiles;
    editorRef = rm.editor;
    chipsRef = rm.chips;

    return null;
}

beforeEach(() => {
    transform.mockReset();
    post.mockReset();
    vi.mocked(useHttp).mockReturnValue({
        transform,
        post,
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
    addFilesRef = null;
    editorRef = null;
    chipsRef = null;
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useAttachments attachGif', () => {
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

/**
 * The DM composer supplies no `imageEdit` endpoints — a DM is not a published
 * post, so there is nothing to crop/beautify into.
 */
describe('useAttachments without imageEdit endpoints', () => {
    function renderHarness(withImageEdit: boolean, media: MediaView[] = []) {
        act(() => {
            root?.render(createElement(Harness, { media, withImageEdit }));
        });
    }

    it('renders no image editor', () => {
        renderHarness(false);

        expect(editorRef).toBeNull();
    });

    it('renders the image editor when the endpoints are supplied', () => {
        renderHarness(true);

        expect(editorRef).not.toBeNull();
    });

    it('leaves chips non-clickable so no editor can be opened', () => {
        renderHarness(false, [existingMedia('m1')]);

        expect(
            (chipsRef as { props: Record<string, unknown> }).props.onImageClick,
        ).toBeUndefined();
    });

    it('keeps chips clickable when the editor is available', () => {
        renderHarness(true, [existingMedia('m1')]);

        expect(
            (chipsRef as { props: Record<string, unknown> }).props.onImageClick,
        ).toBeInstanceOf(Function);
    });

    it('uploads a picked image straight through instead of queueing the editor', async () => {
        renderHarness(false);

        await act(async () => {
            await addFilesRef?.([
                new File(['x'], 'a.png', { type: 'image/png' }),
            ]);
        });

        expect(post).toHaveBeenCalledWith(
            '/engagement/reply-1/media',
            expect.anything(),
        );
        expect(editorRef).toBeNull();
    });

    it('queues a picked image into the editor when the endpoints are supplied', async () => {
        renderHarness(true);

        await act(async () => {
            await addFilesRef?.([
                new File(['x'], 'a.png', { type: 'image/png' }),
            ]);
        });

        expect(post).not.toHaveBeenCalled();
        expect(
            (editorRef as { props: Record<string, unknown> }).props.open,
        ).toBe(true);
    });
});
