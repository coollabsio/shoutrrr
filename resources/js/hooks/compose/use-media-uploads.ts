import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import PostMediaController from '@/actions/App/Http/Controllers/Posts/PostMediaController';
import PostVideoUploadController from '@/actions/App/Http/Controllers/Posts/PostVideoUploadController';
import {
    putWithProgress,
    readVideoMetadata,
    validateVideo,
} from '@/lib/compose/video';
import type { MediaView, PendingUpload, PlatformLimits } from '@/types/compose';

type Options = {
    /** Currently-attached media (drives the one-video / no-mixing rule). */
    media: MediaView[];
    /** Limits for the selected platforms, used to validate a video before upload. */
    videoLimits: PlatformLimits[];
    /** Guarantee a persisted post id before uploading; returns the post id. */
    onEnsurePost: () => Promise<string>;
    /** Append a finished upload to the composer's media. */
    onAddMedia: (media: MediaView) => void;
};

type MediaUploads = {
    pending: PendingUpload[];
    isUploading: boolean;
    /** Validate + upload each file, enforcing the one-video / no-mixing rule. */
    handleFiles: (files: FileList) => Promise<void>;
    dismissPending: (tempId: string) => void;
};

/**
 * Owns the composer's media-upload lifecycle: pending-chip state, object-URL previews,
 * the image (multipart) and video (presigned direct-to-storage) upload flows, and the
 * one-video / no-mixing-with-images rule. The component stays presentational.
 */
export function useMediaUploads({
    media,
    videoLimits,
    onEnsurePost,
    onAddMedia,
}: Options): MediaUploads {
    const imageHttp = useHttp<{ file?: File | null }, { media: MediaView }>({});
    const signHttp = useHttp<
        { content_type: string },
        { key: string; url: string; headers: Record<string, string> }
    >({ content_type: 'video/mp4' });
    const confirmHttp = useHttp<
        {
            key: string;
            duration_seconds: number;
            width: number;
            height: number;
            alt_text: null;
        },
        { media: MediaView }
    >({ key: '', duration_seconds: 0, width: 0, height: 0, alt_text: null });

    const [pending, setPending] = useState<PendingUpload[]>([]);
    const tempSeq = useRef(0);
    // Every object URL we mint, so they can be revoked and not leak.
    const urls = useRef<Set<string>>(new Set());

    useEffect(
        () => () => {
            for (const url of urls.current) {
                URL.revokeObjectURL(url);
            }
            urls.current.clear();
        },
        [],
    );

    const isUploading = pending.some((p) => p.status === 'uploading');

    function mintPreview(file: File): string | undefined {
        try {
            if (typeof URL?.createObjectURL !== 'function') {
                return undefined;
            }
            const url = URL.createObjectURL(file);
            urls.current.add(url);

            return url;
        } catch {
            return undefined;
        }
    }

    // --- Pending-chip lifecycle (shared by both upload flows) ---------------

    function beginUpload(file: File): { tempId: string; previewUrl?: string } {
        tempSeq.current += 1;
        const tempId = `up_${tempSeq.current}`;
        const previewUrl = mintPreview(file);
        setPending((cur) => [
            ...cur,
            { tempId, previewUrl, status: 'uploading' },
        ]);

        return { tempId, previewUrl };
    }

    function failUpload(tempId: string): void {
        setPending((cur) =>
            cur.map((p) =>
                p.tempId === tempId ? { ...p, status: 'error' } : p,
            ),
        );
    }

    function setProgress(tempId: string, progress: number): void {
        setPending((cur) =>
            cur.map((p) => (p.tempId === tempId ? { ...p, progress } : p)),
        );
    }

    function finishUpload(
        tempId: string,
        result: MediaView,
        previewUrl?: string,
    ): void {
        // Prefer the local preview over the server URL to avoid a blank flash.
        onAddMedia(previewUrl ? { ...result, url: previewUrl } : result);
        setPending((cur) => cur.filter((p) => p.tempId !== tempId));
    }

    function dismissPending(tempId: string): void {
        setPending((cur) => {
            const target = cur.find((p) => p.tempId === tempId);
            if (target?.previewUrl && urls.current.delete(target.previewUrl)) {
                URL.revokeObjectURL(target.previewUrl);
            }

            return cur.filter((p) => p.tempId !== tempId);
        });
    }

    // --- Upload flows -------------------------------------------------------

    async function uploadImage(file: File): Promise<void> {
        const { tempId, previewUrl } = beginUpload(file);

        const id = await onEnsurePost();
        if (!id) {
            return failUpload(tempId);
        }

        // transform injects the file at submit time (multipart upload).
        imageHttp.transform(() => ({ file }));
        try {
            const { media: result } = await imageHttp.post(
                PostMediaController.store(id).url,
                { onNetworkError: () => undefined },
            );
            finishUpload(tempId, result, previewUrl);
        } catch {
            failUpload(tempId);
        }
    }

    async function uploadVideo(file: File): Promise<void> {
        let meta;
        try {
            meta = await readVideoMetadata(file);
        } catch {
            toast.error('Could not read that video.');

            return;
        }

        const verdict = validateVideo(
            {
                sizeBytes: file.size,
                mime: file.type,
                durationSeconds: meta.durationSeconds,
                width: meta.width,
                height: meta.height,
            },
            videoLimits,
        );
        if (!verdict.ok) {
            toast.error(verdict.reason);

            return;
        }

        const { tempId, previewUrl } = beginUpload(file);

        const id = await onEnsurePost();
        if (!id) {
            return failUpload(tempId);
        }

        try {
            // 1. Sign (CSRF handled by useHttp) → 2. PUT direct to storage → 3. confirm.
            signHttp.setData({ content_type: 'video/mp4' });
            const signed = await signHttp.post(
                PostVideoUploadController.url(id).url,
                { onNetworkError: () => undefined },
            );

            await putWithProgress(signed.url, signed.headers, file, (pct) =>
                setProgress(tempId, pct),
            );

            confirmHttp.setData({
                key: signed.key,
                duration_seconds: meta.durationSeconds,
                width: meta.width,
                height: meta.height,
                alt_text: null,
            });
            const { media: result } = await confirmHttp.post(
                PostVideoUploadController.store(id).url,
                { onNetworkError: () => undefined },
            );

            finishUpload(tempId, result, previewUrl);
        } catch {
            failUpload(tempId);
        }
    }

    // --- One-video / no-mixing-with-images rule -----------------------------

    async function handleFiles(files: FileList): Promise<void> {
        const hasVideo = media.some((m) => m.kind === 'video');
        const hasImages = media.some((m) => m.kind === 'image');
        // Track this batch too: render-closure `media` is stale for files already
        // dispatched earlier in the same multi-select.
        let videoQueued = false;
        let imageQueued = false;

        for (const file of Array.from(files)) {
            if (file.type.startsWith('video/')) {
                if (hasImages || hasVideo || videoQueued || imageQueued) {
                    toast.error(
                        'A post can contain one video or images, not both.',
                    );
                    continue;
                }
                videoQueued = true;
                await uploadVideo(file);
                continue;
            }

            if (hasVideo || videoQueued) {
                toast.error('Remove the video before adding images.');
                continue;
            }
            // imageQueued does not block more images — multi-image batches all upload.
            imageQueued = true;
            await uploadImage(file);
        }
    }

    return { pending, isUploading, handleFiles, dismissPending };
}
