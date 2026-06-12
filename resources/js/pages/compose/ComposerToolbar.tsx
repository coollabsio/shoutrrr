import { useHttp } from '@inertiajs/react';
import { Image as ImageIcon, Shuffle, Split } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

import PostMediaController from '@/actions/App/Http/Controllers/Posts/PostMediaController';
import { cn } from '@/lib/utils';

import { MediaChips, type PendingUpload } from './MediaChips';
import type { MediaView, PlatformName } from './types';

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
}: Props) {
    const upload = useHttp<{ file: File | null }, { media: MediaView }>({
        file: null,
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

    async function handleFiles(files: FileList) {
        for (const file of Array.from(files)) {
            await uploadFile(file);
        }
        if (input.current) {
            input.current.value = '';
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
                if (e.dataTransfer.files.length > 0) {
                    void handleFiles(e.dataTransfer.files);
                }
            }}
            className="flex flex-wrap items-center gap-1.5 border-t border-border bg-muted/50 px-3 pt-2 pb-2.5 sm:px-[14px]"
        >
            <input
                ref={input}
                type="file"
                accept="image/*"
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

            <MediaChips
                media={media}
                pending={pending}
                activePlatform={activePlatform}
                isExcluded={isExcluded}
                onToggleExclude={onToggleExclude}
                onReorder={onReorder}
                onRemove={onRemove}
                onDismissPending={dismissPending}
            />

            <div className="ml-auto sm:flex-1" />

            {showSplitControls && (
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
                'inline-flex h-7 items-center gap-1.5 rounded-md border border-transparent bg-transparent px-2.5 text-[12px] text-muted-foreground transition-colors',
                'hover:border-border hover:bg-background hover:text-foreground',
                'data-[active=true]:border-border data-[active=true]:bg-background data-[active=true]:text-foreground data-[active=true]:shadow-[0_1px_2px_0_rgb(0_0_0/0.04)]',
            )}
        >
            {children}
        </button>
    );
}
