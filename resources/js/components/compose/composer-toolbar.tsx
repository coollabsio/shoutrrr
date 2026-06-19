import { useHttp } from '@inertiajs/react';
import { Image as ImageIcon, Shuffle, Split } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import PostMediaController from '@/actions/App/Http/Controllers/Posts/PostMediaController';
import PostVideoUploadController from '@/actions/App/Http/Controllers/Posts/PostVideoUploadController';
import { readVideoMetadata, validateVideo } from '@/lib/compose/video';
import { cn } from '@/lib/utils';
import type { MediaView, PlatformLimits, PlatformName } from '@/types/compose';

import { MediaChips, type PendingUpload } from './media-chips';

type Props = {
    /** Active account's platform; undefined on the generic "Post" tab. */
    activePlatform?: PlatformName;
    autoSplit: boolean;
    overrideActive: boolean;
    /** When false, hides Override + Auto-split (generic tab has no platform). */
    showSplitControls?: boolean;
    media: MediaView[];
    onAddMedia: (media: MediaView) => void;
    onRemove: (mediaId: string) => void;
    onReorder: (ids: string[]) => void;
    onToggleAutoSplit: () => void;
    onToggleOverride: () => void;
    isExcluded: (mediaId: string) => boolean;
    onToggleExclude: (mediaId: string) => void;
    /** Guarantee a persisted post id before uploading; returns the post id. */
    onEnsurePost: () => Promise<string>;
    /** Read-only post: show attached media, hide all editing controls. */
    readOnly?: boolean;
    videoLimits: PlatformLimits[];
    /** Reports whether any media upload is currently in flight. */
    onUploadingChange?: (uploading: boolean) => void;
};

export function ComposerToolbar({
    activePlatform,
    autoSplit,
    overrideActive,
    showSplitControls = true,
    media,
    onAddMedia,
    onRemove,
    onReorder,
    onToggleAutoSplit,
    onToggleOverride,
    isExcluded,
    onToggleExclude,
    onEnsurePost,
    readOnly = false,
    videoLimits,
    onUploadingChange,
}: Props) {
    // Image upload (unchanged).
    const upload = useHttp<{ file?: File | null }, { media: MediaView }>({});
    // Sign: POST → { key, url, headers }
    const signHttp = useHttp<
        { content_type: string },
        { key: string; url: string; headers: Record<string, string> }
    >({ content_type: 'video/mp4' });
    // Confirm: POST → { media }
    const confirmHttp = useHttp<
        {
            key: string;
            duration_seconds: number;
            width: number;
            height: number;
            alt_text: null;
        },
        { media: MediaView }
    >({
        key: '',
        duration_seconds: 0,
        width: 0,
        height: 0,
        alt_text: null,
    });
    const input = useRef<HTMLInputElement | null>(null);
    const [pending, setPending] = useState<PendingUpload[]>([]);
    const tempSeq = useRef(0);
    // Track every object URL we mint so they can be revoked and not leak.
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

    // Surface in-flight uploads so the parent can block publish/schedule until
    // every attachment has finished (a still-uploading file isn't yet in `media`,
    // so publishing mid-upload would omit it).
    const isUploading = pending.some((p) => p.status === 'uploading');
    useEffect(() => {
        onUploadingChange?.(isUploading);
    }, [isUploading, onUploadingChange]);

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

    function revoke(url: string | undefined) {
        if (url && urls.current.delete(url)) {
            URL.revokeObjectURL(url);
        }
    }

    async function uploadFile(file: File) {
        tempSeq.current += 1;
        const tempId = `up_${tempSeq.current}`;
        const previewUrl = mintPreview(file);
        setPending((cur) => [
            ...cur,
            { tempId, previewUrl, status: 'uploading' },
        ]);

        const id = await onEnsurePost();
        if (!id) {
            setPending((cur) =>
                cur.map((p) =>
                    p.tempId === tempId ? { ...p, status: 'error' } : p,
                ),
            );

            return;
        }

        // transform injects the file at submit time (multipart upload).
        upload.transform(() => ({ file }));
        try {
            const result = await upload.post(
                PostMediaController.store(id).url,
                {
                    onNetworkError: () => undefined,
                },
            );
            // Prefer the local preview over the server image to avoid a blank
            // flash, by handing addMedia a media view that points at the blob.
            onAddMedia(
                previewUrl
                    ? { ...result.media, url: previewUrl }
                    : result.media,
            );
            setPending((cur) => cur.filter((p) => p.tempId !== tempId));
        } catch {
            setPending((cur) =>
                cur.map((p) =>
                    p.tempId === tempId ? { ...p, status: 'error' } : p,
                ),
            );
        }
    }

    const hasVideo = media.some((m) => m.kind === 'video');
    const hasImages = media.some((m) => m.kind === 'image');

    async function handleFiles(files: FileList) {
        // Track what has been queued in this batch to catch mixing within a
        // single multi-select, where hasVideo/hasImages from the render closure
        // are stale and would not reflect files already dispatched this loop.
        let videoQueued = false;
        let imageQueued = false;
        for (const file of Array.from(files)) {
            const isVideo = file.type.startsWith('video/');

            if (isVideo) {
                if (
                    hasImages ||
                    hasVideo ||
                    media.length > 0 ||
                    videoQueued ||
                    imageQueued
                ) {
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
            // imageQueued does NOT block additional images — multi-image
            // batches must all upload successfully.
            imageQueued = true;
            await uploadFile(file);
        }
        if (input.current) {
            input.current.value = '';
        }
    }

    async function uploadVideo(file: File) {
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

        tempSeq.current += 1;
        const tempId = `up_${tempSeq.current}`;
        const previewUrl = mintPreview(file);
        setPending((cur) => [
            ...cur,
            { tempId, previewUrl, status: 'uploading' },
        ]);

        const id = await onEnsurePost();
        if (!id) {
            setPending((cur) =>
                cur.map((p) =>
                    p.tempId === tempId ? { ...p, status: 'error' } : p,
                ),
            );

            return;
        }

        try {
            // 1. Sign: get a presigned PUT URL from the app (CSRF handled by useHttp).
            signHttp.setData({ content_type: 'video/mp4' });
            const signed = await signHttp.post(
                PostVideoUploadController.url(id).url,
                { onNetworkError: () => undefined },
            );

            // 2. PUT directly to storage — raw XHR, signed headers only, no CSRF.
            await new Promise<void>((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('PUT', signed.url);
                for (const [h, v] of Object.entries(signed.headers ?? {})) {
                    xhr.setRequestHeader(h, v);
                }
                // Only re-render when the whole-number percent changes — a 512 MB upload
                // fires onprogress hundreds of times, but we never need sub-percent updates.
                let lastPct = -1;
                xhr.upload.onprogress = (e) => {
                    if (!e.lengthComputable) {
                        return;
                    }
                    const pct = Math.round((e.loaded / e.total) * 100);
                    if (pct === lastPct) {
                        return;
                    }
                    lastPct = pct;
                    setPending((cur) =>
                        cur.map((p) =>
                            p.tempId === tempId ? { ...p, progress: pct } : p,
                        ),
                    );
                };
                xhr.onload = () =>
                    xhr.status >= 200 && xhr.status < 300
                        ? resolve()
                        : reject(new Error(`upload failed: ${xhr.status}`));
                xhr.onerror = () => reject(new Error('network error'));
                xhr.send(file);
            });

            // 3. Confirm: tell the app the upload is done.
            confirmHttp.setData({
                key: signed.key,
                duration_seconds: meta.durationSeconds,
                width: meta.width,
                height: meta.height,
                alt_text: null,
            });
            const confirmed = await confirmHttp.post(
                PostVideoUploadController.store(id).url,
                { onNetworkError: () => undefined },
            );

            onAddMedia(
                previewUrl
                    ? { ...confirmed.media, url: previewUrl }
                    : confirmed.media,
            );
            setPending((cur) => cur.filter((p) => p.tempId !== tempId));
        } catch {
            setPending((cur) =>
                cur.map((p) =>
                    p.tempId === tempId ? { ...p, status: 'error' } : p,
                ),
            );
        }
    }

    function dismissPending(tempId: string) {
        setPending((cur) => {
            const target = cur.find((p) => p.tempId === tempId);
            revoke(target?.previewUrl);

            return cur.filter((p) => p.tempId !== tempId);
        });
    }

    // Count confirmed media plus uploads still in flight so the badge bumps the
    // instant a file is picked, and settles back if an upload fails.
    const mediaCount =
        media.length + pending.filter((p) => p.status === 'uploading').length;

    return (
        <div
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
                e.preventDefault();
                if (!readOnly && e.dataTransfer.files.length > 0) {
                    void handleFiles(e.dataTransfer.files);
                }
            }}
            className="flex flex-wrap items-center gap-1.5 border-t border-border bg-muted/50 px-3 pt-2 pb-2.5 sm:px-[14px]"
        >
            {!readOnly && (
                <>
                    <input
                        ref={input}
                        type="file"
                        accept={hasVideo ? 'image/*' : 'image/*,video/*'}
                        multiple
                        hidden
                        onChange={(e) => {
                            if (e.target.files && e.target.files.length > 0) {
                                void handleFiles(e.target.files);
                            }
                        }}
                    />

                    <EToolButton
                        title="Add media (⌘⇧M)"
                        onClick={() => input.current?.click()}
                    >
                        <ImageIcon className="size-3.5" aria-hidden="true" />
                        <span>Media</span>
                        {mediaCount > 0 && (
                            <span className="rounded-full bg-foreground px-1.5 py-0.5 font-mono text-[10px] leading-none font-medium text-background tabular-nums">
                                {mediaCount}
                            </span>
                        )}
                    </EToolButton>
                </>
            )}

            <MediaChips
                media={media}
                pending={pending}
                activePlatform={activePlatform}
                isExcluded={isExcluded}
                onToggleExclude={onToggleExclude}
                onReorder={onReorder}
                onRemove={onRemove}
                onDismissPending={dismissPending}
                readOnly={readOnly}
            />

            <div className="ml-auto sm:flex-1" />

            {showSplitControls && !readOnly && (
                <>
                    <EToolButton
                        title={
                            overrideActive
                                ? 'Override on for this account — click to discard and re-sync to base'
                                : 'Override text per account'
                        }
                        active={overrideActive}
                        onClick={onToggleOverride}
                    >
                        <Split className="size-3.5" aria-hidden="true" />
                        <span>
                            {overrideActive ? 'Override on' : 'Override'}
                        </span>
                    </EToolButton>
                    <EToolButton
                        title="Auto-split on platform limits"
                        active={autoSplit}
                        onClick={onToggleAutoSplit}
                    >
                        <Shuffle className="size-3.5" aria-hidden="true" />
                        <span>Auto-split</span>
                    </EToolButton>
                </>
            )}
        </div>
    );
}

function EToolButton({
    children,
    active = false,
    title,
    onClick,
}: {
    children: ReactNode;
    active?: boolean;
    title?: string;
    onClick?: () => void;
}) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            data-active={active}
            className={cn(
                'inline-flex h-8 items-center gap-1.5 rounded-md border border-transparent bg-transparent px-2.5 text-[12px] text-muted-foreground transition-colors sm:h-7',
                'hover:border-border hover:bg-background hover:text-foreground',
                'data-[active=true]:border-border data-[active=true]:bg-background data-[active=true]:text-foreground data-[active=true]:shadow-[0_1px_2px_0_rgb(0_0_0/0.04)]',
            )}
        >
            {children}
        </button>
    );
}
