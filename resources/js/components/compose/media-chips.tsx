import { Eye, EyeOff, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';

import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { MediaView, PendingUpload, PlatformName } from '@/types/compose';

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

type Props = {
    media: MediaView[];
    pending: PendingUpload[];
    /** Active account's platform — display only; undefined on the generic tab. */
    activePlatform?: PlatformName;
    isExcluded: (mediaId: string) => boolean;
    onToggleExclude: (mediaId: string) => void;
    onReorder: (ids: string[]) => void;
    onRemove: (mediaId: string) => void;
    onDismissPending: (tempId: string) => void;
    /** Read-only post: show the images, no add/remove/reorder/exclude affordances. */
    readOnly?: boolean;
    /** Click an image to (re)open it in the editor. */
    onImageClick?: (mediaId: string) => void;
    /** Click a video chip's Edit button to open the video editor. */
    onVideoClick?: (mediaId: string) => void;
};

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

export function MediaChips({
    media,
    pending,
    activePlatform,
    isExcluded,
    onToggleExclude,
    onReorder,
    onRemove,
    onDismissPending,
    readOnly = false,
    onImageClick,
    onVideoClick,
}: Props) {
    const [dragIdx, setDragIdx] = useState<number | null>(null);
    // True only once a real drag (reorder) has started, so the click that ends a
    // drag doesn't also open the editor. Reset at the start of every interaction.
    const dragged = useRef(false);

    if (media.length === 0 && pending.length === 0) {
        return null;
    }

    // Read-only post: plain, non-interactive thumbnails (no remove/drag/exclude).
    if (readOnly) {
        return (
            <div className="ml-0.5 flex items-center gap-2">
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

    return (
        <div className="ml-0.5 flex items-center gap-2">
            {media.map((m, idx) => {
                const excluded = isExcluded(m.id);
                // Both kinds open an editor on click. Videos are always
                // editable (trim works without an encoder); the editor hides the
                // crop tools when the browser can't re-encode.
                const canEdit =
                    m.kind === 'video'
                        ? Boolean(onVideoClick)
                        : Boolean(onImageClick);

                return (
                    <Tooltip key={m.id}>
                        <TooltipTrigger asChild>
                            <div
                                className="group/chip relative"
                                draggable
                                onDragStart={() => {
                                    dragged.current = true;
                                    setDragIdx(idx);
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
                                        // Click the thumbnail to (re)open its editor —
                                        // same gesture for images and videos.
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
                                        excluded &&
                                            'opacity-40 ring-1 ring-destructive/50',
                                        dragIdx === idx &&
                                            'scale-95 opacity-50',
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
                                {/* Per-platform include/exclude — top-left, kept
                                    visible so the toggle is glanceable rather than
                                    hidden behind a hover. */}
                                {activePlatform && (
                                    <button
                                        type="button"
                                        aria-label={
                                            excluded
                                                ? `Include on ${activePlatform}`
                                                : `Exclude on ${activePlatform}`
                                        }
                                        aria-pressed={excluded}
                                        title={
                                            excluded
                                                ? `Hidden on ${activePlatform} — click to include`
                                                : `Shown on ${activePlatform} — click to exclude`
                                        }
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onToggleExclude(m.id);
                                        }}
                                        className={cn(
                                            'absolute -top-1.5 -left-1.5 z-10 grid size-4 place-items-center rounded-full',
                                            'border shadow-sm transition-colors',
                                            excluded
                                                ? 'border-background bg-foreground text-background'
                                                : 'border-border bg-background text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {excluded ? (
                                            <EyeOff
                                                className="size-2.5"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Eye
                                                className="size-2.5"
                                                aria-hidden="true"
                                            />
                                        )}
                                    </button>
                                )}
                            </div>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" className="text-[11px]">
                            {canEdit ? 'Click to edit' : 'Attached media'}
                        </TooltipContent>
                    </Tooltip>
                );
            })}

            {pending.map((p) => (
                <Tooltip key={p.tempId}>
                    <TooltipTrigger asChild>
                        <div
                            className="group/chip relative"
                            aria-label={
                                p.status === 'uploading'
                                    ? 'Uploading media'
                                    : 'Failed upload'
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
                                    <img
                                        src={p.previewUrl}
                                        alt=""
                                        draggable={false}
                                        className={cn(
                                            'size-full object-cover',
                                            p.status === 'uploading' &&
                                                'opacity-50',
                                        )}
                                    />
                                ) : (
                                    <div className="size-full bg-muted" />
                                )}
                                {p.status === 'uploading' && (
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
                            {p.status === 'error' && (
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
                        </div>
                    </TooltipTrigger>
                    <TooltipContent side="bottom" className="text-[11px]">
                        {p.status === 'uploading'
                            ? 'Uploading…'
                            : 'Upload failed — dismiss and retry'}
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}
