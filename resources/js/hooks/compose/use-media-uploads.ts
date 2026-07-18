import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import PostMediaController from '@/actions/App/Http/Controllers/Posts/PostMediaController';
import PostVideoUploadController from '@/actions/App/Http/Controllers/Posts/PostVideoUploadController';
import {
    minVideoBytes,
    putWithProgress,
    readVideoMetadata,
    validateVideo,
    type VideoMeta,
} from '@/lib/compose/video';
import {
    convertErrorMessage,
    VideoConvertError,
} from '@/lib/video-editor/convert-plan';
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
    /** Upload-target URL builders; defaults to the post controllers. */
    endpoints?: {
        imageStore: (ownerId: string) => string;
        videoSign: (ownerId: string) => string;
        videoStore: (ownerId: string) => string;
    };
};

type MediaUploads = {
    pending: PendingUpload[];
    isUploading: boolean;
    /** Validate + upload each file, enforcing the one-video / no-mixing rule. */
    handleFiles: (files: FileList | File[]) => Promise<void>;
    dismissPending: (tempId: string) => void;
    /**
     * Abort an in-flight video upload — cancels the in-browser conversion,
     * compression, or storage PUT, whichever is running, and removes the chip.
     */
    cancelPending: (tempId: string) => void;
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
    endpoints,
}: Options): MediaUploads {
    const ep = endpoints ?? {
        imageStore: (id: string) => PostMediaController.store(id).url,
        videoSign: (id: string) => PostVideoUploadController.url(id).url,
        videoStore: (id: string) => PostVideoUploadController.store(id).url,
    };
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
    // AbortController per in-flight video upload, keyed by its chip's tempId, so
    // the cancel button can stop conversion/compression/PUT mid-flight.
    const aborters = useRef<Map<string, AbortController>>(new Map());

    useEffect(
        () => () => {
            for (const url of urls.current) {
                URL.revokeObjectURL(url);
            }
            urls.current.clear();
            // Unmounting mid-upload: stop any in-flight work rather than let it
            // run to completion against a gone component.
            for (const controller of aborters.current.values()) {
                controller.abort();
            }
            aborters.current.clear();
        },
        [],
    );

    // Compression ('processing') counts as in flight too — the publish/send gates
    // hang off this, so a post must not ship while a video is still being encoded.
    const isUploading = pending.some(
        (p) => p.status === 'uploading' || p.status === 'processing',
    );

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

    function beginUpload(
        file: File,
        kind: PendingUpload['kind'],
        status: PendingUpload['status'] = 'uploading',
    ): { tempId: string; previewUrl?: string } {
        tempSeq.current += 1;
        const tempId = `up_${tempSeq.current}`;
        const previewUrl = mintPreview(file);
        setPending((cur) => [...cur, { tempId, kind, previewUrl, status }]);

        return { tempId, previewUrl };
    }

    function failUpload(tempId: string): void {
        setPending((cur) =>
            cur.map((p) =>
                p.tempId === tempId ? { ...p, status: 'error' } : p,
            ),
        );
    }

    function setStatus(tempId: string, status: PendingUpload['status']): void {
        setPending((cur) =>
            cur.map((p) => (p.tempId === tempId ? { ...p, status } : p)),
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

    function cancelPending(tempId: string): void {
        // Abort the in-flight work (conversion/compression/PUT) so it stops
        // burning CPU and can't add media after the user bailed, then drop the
        // chip. `uploadVideo` sees the aborted signal and returns silently.
        aborters.current.get(tempId)?.abort();
        aborters.current.delete(tempId);
        dismissPending(tempId);
    }

    // --- Upload flows -------------------------------------------------------

    async function uploadImage(file: File): Promise<void> {
        const { tempId, previewUrl } = beginUpload(file, 'image');

        const id = await onEnsurePost();
        if (!id) {
            return failUpload(tempId);
        }

        // transform injects the file at submit time (multipart upload).
        imageHttp.transform(() => ({ file }));
        try {
            const { media: result } = await imageHttp.post(ep.imageStore(id), {
                onNetworkError: () => undefined,
            });
            finishUpload(tempId, result, previewUrl);
        } catch {
            failUpload(tempId);
        }
    }

    async function uploadVideo(file: File): Promise<void> {
        // One controller for the whole operation; the cancel button aborts it to
        // stop conversion, compression, or the PUT — whichever is running.
        const controller = new AbortController();
        const { signal } = controller;
        // The chip the controller is currently registered under (conversion and
        // upload use separate chips). The finally clears whichever is active so
        // the registry never leaks a controller.
        let activeTempId: string | null = null;
        const register = (tempId: string): void => {
            activeTempId = tempId;
            aborters.current.set(tempId, controller);
        };

        try {
            // Non-MP4 input is converted to a platform-ready MP4 in the browser
            // first — the server only ever stores MP4. MP4 files skip this
            // entirely and keep the existing fast path untouched.
            let source = file;
            if (file.type !== 'video/mp4') {
                const { tempId } = beginUpload(file, 'video', 'processing');
                register(tempId);
                try {
                    const { convertToMp4 } =
                        await import('@/lib/video-editor/convert');
                    source = await convertToMp4(
                        file,
                        videoLimits,
                        (fraction) =>
                            setProgress(tempId, Math.round(fraction * 100)),
                        signal,
                    );
                } catch (error) {
                    // The user cancelled: the chip is already gone, stay silent.
                    if (signal.aborted) {
                        return;
                    }
                    const reason =
                        error instanceof VideoConvertError
                            ? error.reason
                            : 'encode-unsupported';
                    toast.error(convertErrorMessage(reason, videoLimits));
                    dismissPending(tempId);

                    return;
                }
                dismissPending(tempId);
                aborters.current.delete(tempId);
                activeTempId = null;
            }

            let meta: VideoMeta;
            try {
                meta = await readVideoMetadata(source);
            } catch {
                toast.error('Could not read that video.');

                return;
            }
            if (signal.aborted) {
                return;
            }

            // Re-encoding can only shrink an over-cap clip's bytes — it can't fix
            // a wrong codec or a too-long runtime. So we compress only when an
            // oversized file is otherwise valid, detected by re-running the gate
            // as if the file already fit (sizeBytes 0). Anything else gets the
            // cheap up-front rejection, so a doomed file never flashes a ghost
            // chip.
            const maxBytes = minVideoBytes(videoLimits);
            const verdict = validateVideo(meta, videoLimits);
            const willCompress =
                !verdict.ok &&
                Number.isFinite(maxBytes) &&
                source.size > maxBytes &&
                validateVideo({ ...meta, sizeBytes: 0 }, videoLimits).ok;

            if (!verdict.ok && !willCompress) {
                toast.error(verdict.reason);

                return;
            }

            const { tempId, previewUrl } = beginUpload(
                source,
                'video',
                willCompress ? 'processing' : 'uploading',
            );
            register(tempId);

            let finalFile = source;
            if (willCompress) {
                let compressed: Blob | null = null;
                try {
                    const { compressVideoToFit } =
                        await import('@/lib/video-editor/compress');
                    compressed = await compressVideoToFit(
                        source,
                        maxBytes,
                        (fraction) =>
                            setProgress(tempId, Math.round(fraction * 100)),
                        undefined,
                        signal,
                    );
                } catch {
                    // Encode threw or was cancelled; the abort check below bails,
                    // otherwise fall through to the gate which rejects the
                    // still-over-cap original.
                }
                if (signal.aborted) {
                    return;
                }

                if (compressed) {
                    finalFile = new File([compressed], source.name, {
                        type: 'video/mp4',
                    });
                    try {
                        // Downscaling changes width/height/size — re-read so the
                        // final gate and confirm payload reflect the real output.
                        meta = await readVideoMetadata(finalFile);
                    } catch {
                        // compressVideoToFit already guarantees the blob fits; if
                        // the re-read fails, trust that size over the stale
                        // original rather than dropping an otherwise-valid upload.
                        meta = { ...meta, sizeBytes: finalFile.size };
                    }
                }

                setStatus(tempId, 'uploading');
                setProgress(tempId, 0);

                const finalVerdict = validateVideo(meta, videoLimits);
                if (!finalVerdict.ok) {
                    toast.error(finalVerdict.reason);
                    dismissPending(tempId);

                    return;
                }
            }

            // Bail before touching the draft if the user cancelled during the
            // compress re-read, so a cancel doesn't create a post it abandoned.
            if (signal.aborted) {
                return;
            }

            const id = await onEnsurePost();
            if (!id) {
                return failUpload(tempId);
            }
            if (signal.aborted) {
                return;
            }

            try {
                // 1. Sign (CSRF handled by useHttp) → 2. PUT direct to storage → 3. confirm.
                signHttp.setData({ content_type: 'video/mp4' });
                const signed = await signHttp.post(ep.videoSign(id), {
                    onNetworkError: () => undefined,
                });
                if (signal.aborted) {
                    return;
                }

                await putWithProgress(
                    signed.url,
                    signed.headers,
                    finalFile,
                    (pct) => setProgress(tempId, pct),
                    signal,
                );

                confirmHttp.setData({
                    key: signed.key,
                    duration_seconds: meta.durationSeconds,
                    width: meta.width,
                    height: meta.height,
                    alt_text: null,
                });
                const { media: result } = await confirmHttp.post(
                    ep.videoStore(id),
                    { onNetworkError: () => undefined },
                );
                if (signal.aborted) {
                    return;
                }

                finishUpload(tempId, result, previewUrl);
            } catch {
                // A cancel aborts the PUT/confirm; the chip is already gone.
                if (signal.aborted) {
                    return;
                }
                failUpload(tempId);
            }
        } finally {
            if (activeTempId) {
                aborters.current.delete(activeTempId);
            }
        }
    }

    // --- One-video / no-mixing-with-images rule -----------------------------

    async function handleFiles(files: FileList | File[]): Promise<void> {
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

    return { pending, isUploading, handleFiles, dismissPending, cancelPending };
}
