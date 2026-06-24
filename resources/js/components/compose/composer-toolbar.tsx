import { Image as ImageIcon, Shuffle, Split } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef } from 'react';

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
    // instant a file is picked, and settles back if an upload fails.
    const mediaCount =
        media.length + pending.filter((p) => p.status === 'uploading').length;

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
