import { Popover as PopoverPrimitive } from '@base-ui/react/popover';
import { Image as ImageIcon, Shuffle, Smile, Split } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

import EmojiPicker from '@/components/compose/emoji-picker';
import type { EmojiSkinTone } from '@/lib/compose/emoji/types';
import { cn } from '@/lib/utils';
import type { MediaView, PendingUpload, PlatformName } from '@/types/compose';

import { MediaChips } from './media-chips';

type Props = {
    /** Active account's platform; undefined on the generic "Post" tab. */
    activePlatform?: PlatformName;
    autoSplit: boolean;
    overrideActive: boolean;
    /** When false, hides Override + Auto-split (generic tab has no platform). */
    showSplitControls?: boolean;
    media: MediaView[];
    onRemove: (mediaId: string) => void;
    onReorder: (ids: string[]) => void;
    onToggleAutoSplit: () => void;
    onToggleOverride: () => void;
    isExcluded: (mediaId: string) => boolean;
    onToggleExclude: (mediaId: string) => void;
    /** Read-only post: show attached media, hide all editing controls. */
    readOnly?: boolean;
    /** In-flight uploads (owned by the parent's useMediaUploads). */
    pending: PendingUpload[];
    /** Validate + upload a picked/dropped batch. */
    handleFiles: (files: FileList) => Promise<void>;
    /** Drop a failed/pending upload chip. */
    dismissPending: (tempId: string) => void;
    /** Click an attached image to (re)open it in the editor. */
    onImageClick?: (mediaId: string) => void;
    /** Click a video chip's Edit button to open the video editor. */
    onVideoClick?: (mediaId: string) => void;
    /** Insert a chosen emoji at the editor caret. */
    onInsertEmoji: (emoji: string) => void;
    /** Recently-used emoji, newest first. */
    emojiRecents: string[];
    emojiSkinTone: EmojiSkinTone;
    onEmojiSkinToneChange: (tone: EmojiSkinTone) => void;
};

export function ComposerToolbar({
    activePlatform,
    autoSplit,
    overrideActive,
    showSplitControls = true,
    media,
    onRemove,
    onReorder,
    onToggleAutoSplit,
    onToggleOverride,
    isExcluded,
    onToggleExclude,
    readOnly = false,
    pending,
    handleFiles,
    dismissPending,
    onImageClick,
    onVideoClick,
    onInsertEmoji,
    emojiRecents,
    emojiSkinTone,
    onEmojiSkinToneChange,
}: Props) {
    const input = useRef<HTMLInputElement | null>(null);

    // Process a picked/dropped batch, then reset the input so re-picking the same
    // file fires onChange again.
    function acceptFiles(files: FileList) {
        void handleFiles(files).finally(() => {
            if (input.current) {
                input.current.value = '';
            }
        });
    }

    const hasVideo = media.some((m) => m.kind === 'video');
    // Count confirmed media plus uploads still in flight so the badge bumps the
    // instant a file is picked, and settles back if an upload fails. "processing"
    // (client-side compression) is in flight too, so it counts.
    const mediaCount =
        media.length +
        pending.filter(
            (p) => p.status === 'uploading' || p.status === 'processing',
        ).length;

    return (
        <div
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
                e.preventDefault();
                if (!readOnly && e.dataTransfer.files.length > 0) {
                    acceptFiles(e.dataTransfer.files);
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
                                acceptFiles(e.target.files);
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
                onImageClick={onImageClick}
                onVideoClick={onVideoClick}
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

            {!readOnly && (
                <EmojiPopover
                    recents={emojiRecents}
                    skinTone={emojiSkinTone}
                    onSkinToneChange={onEmojiSkinToneChange}
                    onSelect={onInsertEmoji}
                />
            )}
        </div>
    );
}

function EmojiPopover({
    recents,
    skinTone,
    onSkinToneChange,
    onSelect,
}: {
    recents: string[];
    skinTone: EmojiSkinTone;
    onSkinToneChange: (tone: EmojiSkinTone) => void;
    onSelect: (emoji: string) => void;
}) {
    const [open, setOpen] = useState(false);
    // Mount the picker once and keep it alive. Frimousse re-reads and re-parses
    // the ~775KB emoji dataset and rebuilds its store on every fresh mount, so
    // unmounting on close (the default) made each reopen — and the select
    // that closes it — sluggish. We warm it during browser idle (never on the
    // click, which the parse would block) and Portal `keepMounted` keeps it
    // alive; when closed it's hidden via a transition, not unmounted.
    const [mounted, setMounted] = useState(false);
    useEffect(() => {
        if (mounted) {
            return;
        }
        if (open) {
            setMounted(true);

            return;
        }
        const idle = window as Window & {
            requestIdleCallback?: (callback: () => void) => number;
            cancelIdleCallback?: (handle: number) => void;
        };
        if (typeof idle.requestIdleCallback === 'function') {
            const handle = idle.requestIdleCallback(() => setMounted(true));

            return () => idle.cancelIdleCallback?.(handle);
        }
        const handle = window.setTimeout(() => setMounted(true), 500);

        return () => window.clearTimeout(handle);
    }, [mounted, open]);

    return (
        <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
            <PopoverPrimitive.Trigger
                render={
                    <button
                        type="button"
                        title="Emoji"
                        data-active={open}
                        className={cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-md border border-transparent bg-transparent px-2.5 text-[12px] text-muted-foreground transition-colors sm:h-7',
                            'hover:border-border hover:bg-background hover:text-foreground',
                            'data-[active=true]:border-border data-[active=true]:bg-background data-[active=true]:text-foreground data-[active=true]:shadow-[0_1px_2px_0_rgb(0_0_0/0.04)]',
                        )}
                    />
                }
            >
                <Smile className="size-3.5" aria-hidden="true" />
                <span>Emoji</span>
            </PopoverPrimitive.Trigger>
            {mounted && (
                <PopoverPrimitive.Portal keepMounted>
                    <PopoverPrimitive.Positioner
                        align="end"
                        side="top"
                        sideOffset={8}
                        // While closed the kept-warm popover stays mounted and
                        // positioned over the composer; make the positioner
                        // click-through so it doesn't swallow clicks on the tab
                        // strip / controls beneath it.
                        className="isolate z-50 data-closed:pointer-events-none"
                    >
                        <PopoverPrimitive.Popup
                            data-keep-warm=""
                            initialFocus={false}
                            className={cn(
                                'z-50 w-[336px] overflow-hidden rounded-2xl bg-popover text-popover-foreground shadow-lg ring-1 ring-foreground/5 outline-hidden dark:ring-foreground/10',
                                // Same fade+zoom feel as the notification bell, but
                                // driven by a CSS transition instead of a keyframe
                                // animate-in/-out. The picker is kept warm and
                                // prewarmed while closed, so a keyframe `animate-out`
                                // would flash it on that first hidden mount; a
                                // transition only runs on real state changes.
                                // opacity/transform are GPU-composited, so it stays
                                // smooth on the heavy virtualized grid.
                                'origin-(--transform-origin) transition-[opacity,transform] duration-100 ease-out',
                                'data-open:scale-100 data-open:opacity-100',
                                'data-closed:pointer-events-none data-closed:scale-95 data-closed:opacity-0',
                            )}
                        >
                            <EmojiPicker
                                recents={recents}
                                skinTone={skinTone}
                                onSkinToneChange={onSkinToneChange}
                                onSelect={(emoji) => {
                                    onSelect(emoji);
                                    setOpen(false);
                                }}
                            />
                        </PopoverPrimitive.Popup>
                    </PopoverPrimitive.Positioner>
                </PopoverPrimitive.Portal>
            )}
        </PopoverPrimitive.Root>
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
