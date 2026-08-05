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
const post = vi.fn();
const onAddMedia = vi.fn();

let root: Root | null = null;
let container: HTMLDivElement | null = null;
let trackPendingRef:
    | ((
          chip: { kind: 'image' | 'video'; previewUrl?: string },
          work: () => Promise<MediaView>,
          segmentRef?: string,
      ) => Promise<void>)
    | null = null;
let handleFilesRef: ((files: FileList | File[]) => Promise<void>) | null = null;
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

function imageFile(): File {
    return new File(['pixel'], 'photo.png', { type: 'image/png' });
}

function Harness({
    activeSegmentRef = () => '__head__',
    mediaForSegment = () => [],
}: {
    activeSegmentRef?: () => string;
    mediaForSegment?: (segmentRef: string) => MediaView[];
}) {
    const uploads = useMediaUploads({
        mediaForSegment,
        videoLimits: [],
        onEnsurePost: async () => 'post-1',
        onAddMedia,
        activeSegmentRef,
    });
    trackPendingRef = uploads.trackPending;
    handleFilesRef = uploads.handleFiles;
    pendingRef = uploads.pending;

    return null;
}

function render(
    props: {
        activeSegmentRef?: () => string;
        mediaForSegment?: (segmentRef: string) => MediaView[];
    } = {},
) {
    act(() => {
        root?.render(createElement(Harness, props));
    });
}

beforeEach(() => {
    transform.mockReset();
    post.mockReset();
    onAddMedia.mockReset();
    vi.mocked(useHttp).mockReturnValue({
        transform,
        post,
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
    handleFilesRef = null;
    pendingRef = [];
    vi.clearAllMocks();
});

describe('useMediaUploads trackPending', () => {
    it('drops the chip once the work resolves', async () => {
        render();

        const result = mediaView();
        await act(async () => {
            await trackPendingRef?.({ kind: 'image' }, async () => result);
        });

        expect(pendingRef).toHaveLength(0);
        expect(onAddMedia).toHaveBeenCalledWith(result, '__head__');
    });

    it('keeps the chip with an error status when the work rejects', async () => {
        render();

        await act(async () => {
            await trackPendingRef?.({ kind: 'image' }, async () => {
                throw new Error('That GIF could not be attached.');
            });
        });

        expect(pendingRef).toHaveLength(1);
        expect(pendingRef[0].status).toBe('error');
        expect(onAddMedia).not.toHaveBeenCalled();
    });

    it('tags the added media with the segment ref captured at call time', async () => {
        let active = 'b1';
        render({ activeSegmentRef: () => active });

        const result = mediaView();
        await act(async () => {
            const p = trackPendingRef?.({ kind: 'image' }, async () => result);
            // Caret moves to another segment while the GIF fetch is in flight.
            active = '__head__';
            await p;
        });

        expect(onAddMedia).toHaveBeenCalledWith(result, 'b1');
    });
});

describe('useMediaUploads handleFiles', () => {
    it('attaches an uploaded file to the segment active when the upload began', async () => {
        let active = 'b1';
        render({ activeSegmentRef: () => active });
        post.mockResolvedValue({ media: mediaView() });

        await act(async () => {
            const p = handleFilesRef?.([imageFile()]);
            // The caret moves to another segment before the upload resolves;
            // the media must still land on the segment active at call time.
            active = '__head__';
            await p;
        });

        expect(onAddMedia).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'gif-1' }),
            'b1',
        );
    });
});

describe('useMediaUploads image upload failure', () => {
    it('carries the server validation message onto the failed chip instead of a generic error', async () => {
        render();
        post.mockImplementation(
            (
                _url: string,
                opts: { onError?: (errors: Record<string, string>) => void },
            ) => {
                opts.onError?.({ file: 'The image resolution is too large.' });

                return Promise.reject(new Error('422'));
            },
        );

        await act(async () => {
            await handleFilesRef?.([imageFile()]);
        });

        expect(pendingRef).toHaveLength(1);
        expect(pendingRef[0].status).toBe('error');
        expect(pendingRef[0].errorMessage).toBe(
            'The image resolution is too large.',
        );
    });
});

describe('useMediaUploads pending chip carries the segment it was targeting', () => {
    it('tags the pending chip with the segment active when the upload began, not wherever the caret moves to later', async () => {
        let active = 'b1';
        render({ activeSegmentRef: () => active });

        // Never resolves — keeps the chip in the "pending" state so it can be
        // inspected before any upload completion logic runs.
        post.mockImplementation(() => new Promise(() => {}));

        act(() => {
            void handleFilesRef?.([imageFile()]);
        });

        // Caret moves to a different segment while the upload is still in flight.
        active = '__head__';

        expect(pendingRef).toHaveLength(1);
        expect(pendingRef[0].segmentRef).toBe('b1');
    });
});

describe('useMediaUploads handleFiles media-mixing rule is scoped to the active segment', () => {
    it('blocks an image upload when a video already sits in the same thread segment', async () => {
        render({
            activeSegmentRef: () => 'b1',
            mediaForSegment: (ref) =>
                ref === 'b1' ? [{ ...mediaView(), kind: 'video' }] : [],
        });
        post.mockResolvedValue({ media: mediaView() });

        await act(async () => {
            await handleFilesRef?.([imageFile()]);
        });

        expect(onAddMedia).not.toHaveBeenCalled();
    });

    it('does not block an image upload when the only video lives in a different thread segment', async () => {
        render({
            activeSegmentRef: () => 'b1',
            mediaForSegment: (ref) =>
                ref === 'other-segment'
                    ? [{ ...mediaView(), kind: 'video' }]
                    : [],
        });
        post.mockResolvedValue({ media: mediaView() });

        await act(async () => {
            await handleFilesRef?.([imageFile()]);
        });

        expect(onAddMedia).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'gif-1' }),
            'b1',
        );
    });
});
