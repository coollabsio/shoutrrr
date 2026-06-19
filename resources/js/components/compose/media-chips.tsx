import type { ReactNode } from 'react';
import { useState } from 'react';

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
                    <span className="absolute right-0.5 bottom-0.5 rounded bg-black/70 px-1 font-mono text-[8px] leading-tight text-white tabular-nums">
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
                    : 'opacity-0 group-focus-within/chip:opacity-100 group-hover/chip:opacity-100',
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
}: Props) {
    const [dragIdx, setDragIdx] = useState<number | null>(null);

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

                return (
                    <Tooltip key={m.id}>
                        <TooltipTrigger asChild>
                            <div
                                className="group/chip relative"
                                draggable
                                onDragStart={() => setDragIdx(idx)}
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
                                    aria-label={`Media ${idx + 1}`}
                                    aria-pressed={!excluded}
                                    onClick={() => onToggleExclude(m.id)}
                                    className={cn(
                                        'block size-7 cursor-grab overflow-hidden rounded-md border border-border active:cursor-grabbing',
                                        'transition-[opacity,transform]',
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
                                    ×
                                </CornerButton>
                            </div>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" className="text-[11px]">
                            {activePlatform
                                ? excluded
                                    ? `Excluded on ${activePlatform} — click to include`
                                    : `Included on ${activePlatform} — click to exclude`
                                : 'Attached media'}
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
                                    ×
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
