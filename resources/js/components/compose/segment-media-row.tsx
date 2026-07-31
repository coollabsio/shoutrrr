import { ImagePlay, Paperclip, X } from 'lucide-react';
import type { DragEvent, ReactNode } from 'react';
import { useRef, useState } from 'react';

import { GifPopover } from '@/components/compose/gif-popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getMediaDrag, setMediaDrag } from '@/lib/compose/media-dnd';
import { cn } from '@/lib/utils';
import type { MediaView, PendingUpload } from '@/types/compose';
import type { GifItem } from '@/types/gifs';

/** Shared look for the row's hover-reveal "add" affordances. */
const addButtonClass = cn(
    'inline-flex size-7 shrink-0 items-center justify-center rounded-md border border-dashed border-border/70 text-muted-foreground',
    'transition-[opacity,color,border-color] hover:border-border hover:text-foreground',
    // Always visible on touch (no hover); reveal on hover/focus on pointer
    // devices so an empty row doesn't sit bare.
    'max-md:opacity-100 md:opacity-0 md:group-focus-within/row:opacity-100 md:group-hover/row:opacity-100',
    // Stay visible while its own popover is open, even if the pointer has
    // moved off the row onto the popup content.
    'data-[active=true]:border-border data-[active=true]:text-foreground data-[active=true]:opacity-100',
);

function formatDuration(seconds: number | null): string | null {
    if (seconds === null || seconds <= 0) {
        return null;
    }
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;

    return `${m}:${String(s).padStart(2, '0')}`;
}

function MediaThumb({ media }: { media: MediaView }) {
    if (media.kind === 'video') {
        const label = formatDuration(media.duration_seconds);

        return (
            <div className="relative size-full">
                <video
                    src={media.url}
                    muted
                    playsInline
                    preload="metadata"
                    className="size-full object-cover"
                />
                {label && (
                    <span className="absolute bottom-0.5 left-0.5 rounded bg-black/70 px-1 font-mono text-[8px] leading-tight text-white tabular-nums">
                        {label}
                    </span>
                )}
            </div>
        );
    }

    return (
        <img
            src={media.url}
            alt={media.alt_text ?? ''}
            draggable={false}
            className="size-full object-cover"
        />
    );
}

/** A square overlay button that protrudes past the chip's top-right corner. */
function CornerButton({
    label,
    onClick,
    always = false,
    children,
}: {
    label: string;
    onClick: () => void;
    always?: boolean;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
            className={cn(
                'absolute -top-1.5 -right-1.5 z-10 grid size-4 place-items-center rounded-full',
                'border border-background bg-destructive text-[11px] leading-none text-destructive-foreground shadow-sm',
                'transition-opacity hover:bg-destructive/90',
                always
                    ? 'flex'
                    : // Always visible on touch (no hover); reveal on hover/focus on pointer devices.
                      'max-md:opacity-100 md:opacity-0 md:group-focus-within/chip:opacity-100 md:group-hover/chip:opacity-100',
            )}
        >
            {children}
        </button>
    );
}

type Props = {
    /** The segment (thread post) this row's media belongs to. */
    segmentRef: string;
    media: MediaView[];
    pending?: PendingUpload[];
    /** Read-only post: plain thumbnails, no add/remove/reorder affordances. */
    readOnly?: boolean;
    onRemove: (mediaId: string) => void;
    onReorder: (ids: string[]) => void;
    /** Another segment's chip was dropped onto this row — reassign it here. */
    onDropMedia?: (mediaId: string, segmentRef: string) => void;
    /** Click an image to (re)open it in the editor. */
    onImageClick?: (mediaId: string) => void;
    /** Click a video's Edit button to open the video editor. */
    onVideoClick?: (mediaId: string) => void;
    /** Attach media to this segment (opens the file picker targeting it). */
    onAddClick: () => void;
    /**
     * Attach a chosen GIF/sticker/clip to this segment. Absent hides the
     * button (read-only, or GIFs disabled for the instance).
     */
    onAttachGif?: (item: GifItem) => void;
    /** Drop a failed/pending upload chip. */
    onDismissPending?: (tempId: string) => void;
    /** Abort an in-flight video conversion/upload and drop its chip. */
    onCancelPending?: (tempId: string) => void;
};

/**
 * One authored segment's attached media — a thin per-segment wrapper around
 * the thumbnail/remove/reorder markup `MediaChips` used to render globally.
 * Unlike `MediaChips`, there is no per-account exclude toggle here: per-tab
 * divergence now happens by dragging/removing media directly on that tab.
 */
export function SegmentMediaRow({
    segmentRef,
    media,
    pending = [],
    readOnly = false,
    onRemove,
    onReorder,
    onDropMedia,
    onImageClick,
    onVideoClick,
    onAddClick,
    onAttachGif,
    onDismissPending,
    onCancelPending,
}: Props) {
    const [dragIdx, setDragIdx] = useState<number | null>(null);
    // True only once a real drag (reorder) has started, so the click that ends a
    // drag doesn't also open the editor. Reset at the start of every interaction.
    const dragged = useRef(false);

    const isEmpty = media.length === 0 && pending.length === 0;

    if (isEmpty && readOnly) {
        return null;
    }

    // Read-only post: plain, non-interactive thumbnails (no remove/drag).
    if (readOnly) {
        return (
            <div className="flex items-center gap-2">
                {media.map((m) => (
                    <div
                        key={m.id}
                        className="size-7 overflow-hidden rounded-md border border-border"
                    >
                        <MediaThumb media={m} />
                    </div>
                ))}
            </div>
        );
    }

    function reorder(from: number, to: number) {
        if (from === to || from < 0 || to < 0) {
            return;
        }
        const ids = media.map((m) => m.id);
        const moved = ids[from];
        if (moved === undefined) {
            return;
        }
        ids.splice(from, 1);
        ids.splice(to, 0, moved);
        onReorder(ids);
    }

    // Cross-row drop: dropping a chip dragged from another segment's row
    // reassigns it here via `onDropMedia`. A same-row reorder drop is handled
    // per-thumbnail below and never reaches this id (it's already in `media`).
    function handleRowDrop(e: DragEvent) {
        const draggedId = getMediaDrag(e);
        if (!draggedId || media.some((m) => m.id === draggedId)) {
            return;
        }
        e.preventDefault();
        onDropMedia?.(draggedId, segmentRef);
    }

    return (
        <div
            className="group/row flex min-h-7 items-center gap-2 py-0.5"
            onDragOver={(e) => e.preventDefault()}
            onDrop={handleRowDrop}
        >
            {media.map((m, idx) => {
                const isGif = m.mime === 'image/gif';
                // Videos open the trim editor; static images open the beautifier.
                // GIFs open neither — the beautifier would flatten them to a
                // static PNG — so they're attach-only.
                const canEdit =
                    m.kind === 'video'
                        ? Boolean(onVideoClick)
                        : Boolean(onImageClick) && !isGif;

                return (
                    <Tooltip key={m.id}>
                        <TooltipTrigger
                            render={
                                <div
                                    className="group/chip relative"
                                    draggable
                                    onDragStart={(e) => {
                                        dragged.current = true;
                                        setDragIdx(idx);
                                        setMediaDrag(e, m.id);
                                    }}
                                    onDragOver={(e) => e.preventDefault()}
                                    onDrop={(e) => {
                                        e.preventDefault();
                                        if (dragIdx !== null) {
                                            reorder(dragIdx, idx);
                                        }
                                        setDragIdx(null);
                                    }}
                                    onDragEnd={() => setDragIdx(null)}
                                />
                            }
                        >
                            <button
                                type="button"
                                aria-label={
                                    canEdit
                                        ? `Edit media ${idx + 1}`
                                        : `Media ${idx + 1}`
                                }
                                onPointerDown={() => {
                                    dragged.current = false;
                                }}
                                onClick={() => {
                                    // Skip the click that ends a reorder drag.
                                    if (dragged.current || !canEdit) {
                                        return;
                                    }
                                    if (m.kind === 'video') {
                                        onVideoClick?.(m.id);
                                    } else {
                                        onImageClick?.(m.id);
                                    }
                                }}
                                className={cn(
                                    'block size-7 overflow-hidden rounded-md border border-border',
                                    'transition-[opacity,transform]',
                                    canEdit
                                        ? 'cursor-pointer'
                                        : 'cursor-default',
                                    dragIdx === idx && 'scale-95 opacity-50',
                                )}
                            >
                                <MediaThumb media={m} />
                            </button>
                            <CornerButton
                                label="Remove"
                                onClick={() => onRemove(m.id)}
                            >
                                <X
                                    className="size-2.5 text-black"
                                    aria-hidden="true"
                                />
                            </CornerButton>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" className="text-[11px]">
                            {canEdit ? 'Click to edit' : 'Attached media'}
                        </TooltipContent>
                    </Tooltip>
                );
            })}

            {pending.map((p) => {
                const inFlight =
                    p.status === 'uploading' || p.status === 'processing';

                return (
                    <Tooltip key={p.tempId}>
                        <TooltipTrigger
                            render={
                                <div
                                    className="group/chip relative"
                                    aria-label={
                                        p.status === 'processing'
                                            ? 'Processing video'
                                            : p.status === 'uploading'
                                              ? 'Uploading media'
                                              : 'Failed upload'
                                    }
                                />
                            }
                        >
                            <div
                                className={cn(
                                    'relative size-7 overflow-hidden rounded-md border border-border',
                                    p.status === 'error' &&
                                        'ring-1 ring-destructive/60',
                                )}
                            >
                                {p.previewUrl ? (
                                    p.kind === 'video' ? (
                                        <video
                                            src={p.previewUrl}
                                            muted
                                            playsInline
                                            preload="metadata"
                                            className={cn(
                                                'size-full object-cover',
                                                inFlight && 'opacity-50',
                                            )}
                                        />
                                    ) : (
                                        <img
                                            src={p.previewUrl}
                                            alt=""
                                            draggable={false}
                                            className={cn(
                                                'size-full object-cover',
                                                inFlight && 'opacity-50',
                                            )}
                                        />
                                    )
                                ) : (
                                    <div className="size-full bg-muted" />
                                )}
                                {inFlight && (
                                    <div className="absolute inset-0 grid place-items-center bg-background/30">
                                        {p.progress !== undefined ? (
                                            <span className="font-mono text-[7px] leading-none font-semibold text-foreground">
                                                {p.progress}%
                                            </span>
                                        ) : (
                                            <span className="size-3 animate-spin rounded-full border-2 border-foreground/70 border-t-transparent" />
                                        )}
                                    </div>
                                )}
                            </div>
                            {p.status === 'error' && onDismissPending && (
                                <CornerButton
                                    label="Dismiss failed upload"
                                    onClick={() => onDismissPending(p.tempId)}
                                    always
                                >
                                    <X
                                        className="size-2.5 text-black"
                                        aria-hidden="true"
                                    />
                                </CornerButton>
                            )}
                            {inFlight &&
                                p.kind === 'video' &&
                                onCancelPending && (
                                    <CornerButton
                                        label="Cancel"
                                        onClick={() =>
                                            onCancelPending(p.tempId)
                                        }
                                    >
                                        <X
                                            className="size-2.5 text-black"
                                            aria-hidden="true"
                                        />
                                    </CornerButton>
                                )}
                        </TooltipTrigger>
                        <TooltipContent side="bottom" className="text-[11px]">
                            {p.status === 'processing'
                                ? 'Processing…'
                                : p.status === 'uploading'
                                  ? 'Uploading…'
                                  : 'Upload failed — dismiss and retry'}
                        </TooltipContent>
                    </Tooltip>
                );
            })}

            {onAttachGif && (
                <GifPopover
                    onSelect={onAttachGif}
                    align="start"
                    tooltip="GIFs, stickers & clips"
                    trigger={(open) => (
                        <button
                            type="button"
                            aria-label="GIFs, stickers and clips"
                            data-active={open}
                            className={addButtonClass}
                        />
                    )}
                >
                    <ImagePlay className="size-3.5" aria-hidden="true" />
                </GifPopover>
            )}

            <button
                type="button"
                aria-label="Add media"
                onClick={onAddClick}
                className={addButtonClass}
            >
                <Paperclip className="size-3.5" aria-hidden="true" />
            </button>
        </div>
    );
}
