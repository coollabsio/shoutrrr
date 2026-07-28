/** @vitest-environment jsdom */

import { useHttp } from '@inertiajs/react';
import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { MediaView, PendingUpload } from '@/types/compose';

import { useMediaUploads } from '../use-media-uploads';

vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
}));

vi.mock('@/actions/App/Http/Controllers/Posts/PostMediaController', () => ({
    default: { store: (id: string) => ({ url: `/posts/${id}/media` }) },
}));

vi.mock(
    '@/actions/App/Http/Controllers/Posts/PostVideoUploadController',
    () => ({
        default: {
            url: (id: string) => ({ url: `/posts/${id}/video/sign` }),
            store: (id: string) => ({ url: `/posts/${id}/video` }),
        },
    }),
);

const transform = vi.fn();
const onAddMedia = vi.fn();

let root: Root | null = null;
let container: HTMLDivElement | null = null;
let trackPendingRef:
    | ((
          chip: { kind: 'image' | 'video'; previewUrl?: string },
          work: () => Promise<MediaView>,
      ) => Promise<void>)
    | null = null;
let pendingRef: PendingUpload[] = [];

function mediaView(): MediaView {
    return {
        id: 'gif-1',
        url: 'https://cdn.test/gif-1.gif',
        mime: 'image/gif',
        kind: 'image',
        alt_text: null,
        duration_seconds: null,
        position: 0,
        edit_settings: null,
        source_url: null,
        edit_url: '/media/gif-1/edit',
        source_edit_url: null,
    };
}

function Harness() {
    const uploads = useMediaUploads({
        media: [],
        videoLimits: [],
        onEnsurePost: async () => 'post-1',
        onAddMedia,
    });
    trackPendingRef = uploads.trackPending;
    pendingRef = uploads.pending;

    return null;
}

beforeEach(() => {
    transform.mockReset();
    onAddMedia.mockReset();
    vi.mocked(useHttp).mockReturnValue({
        transform,
        post: vi.fn(),
        processing: false,
    } as unknown as ReturnType<typeof useHttp>);
    container = document.createElement('div');
    root = createRoot(container);
});

afterEach(() => {
    act(() => root?.unmount());
    root = null;
    container = null;
    trackPendingRef = null;
    pendingRef = [];
    vi.clearAllMocks();
});

describe('useMediaUploads trackPending', () => {
    it('drops the chip once the work resolves', async () => {
        act(() => {
            root?.render(createElement(Harness));
        });

        const result = mediaView();
        await act(async () => {
            await trackPendingRef?.({ kind: 'image' }, async () => result);
        });

        expect(pendingRef).toHaveLength(0);
        expect(onAddMedia).toHaveBeenCalledWith(result);
    });

    it('keeps the chip with an error status when the work rejects', async () => {
        act(() => {
            root?.render(createElement(Harness));
        });

        await act(async () => {
            await trackPendingRef?.({ kind: 'image' }, async () => {
                throw new Error('That GIF could not be attached.');
            });
        });

        expect(pendingRef).toHaveLength(1);
        expect(pendingRef[0].status).toBe('error');
        expect(onAddMedia).not.toHaveBeenCalled();
    });
});
