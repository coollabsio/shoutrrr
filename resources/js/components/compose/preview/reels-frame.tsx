import {
    Bookmark,
    Film,
    Heart,
    MessageCircle,
    MoreHorizontal,
    Music2,
    Send,
    Share2,
    ThumbsUp,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { PlatformPreview } from '@/lib/compose/platform-preview';
import { LinkedText } from '@/lib/linked-text';
import { cn } from '@/lib/utils';

import {
    handleName,
    PREVIEW_ENTITY_LINK,
    previewInitials,
    reelVideo,
} from './helpers';
import { PreviewVideo } from './preview-video';

type ReelsPlatform = 'instagram' | 'facebook';

function RailAction({ icon, label }: { icon: ReactNode; label: string }) {
    return (
        <span className="flex flex-col items-center gap-1 drop-shadow">
            {icon}
            <span className="text-[10px] font-medium text-white/90">
                {label}
            </span>
        </span>
    );
}

/**
 * A 9:16 Reel preview. A Reel is a single video that keeps its caption, so the
 * frame plays the first video attachment (autoplaying muted) with the caption,
 * author, and the right-hand engagement rail — matching what the connectors
 * publish. A draft with no video shows a prompt instead.
 */
export function ReelsFrame({
    preview,
    platform,
}: {
    preview: PlatformPreview;
    platform: ReelsPlatform;
}) {
    const video = reelVideo(preview);
    const item = preview.items[0];
    const caption = item?.text ?? '';
    const name =
        platform === 'facebook' ? preview.accountName : handleName(preview);

    return (
        <div className="mx-auto w-full max-w-[248px]">
            <div className="relative aspect-[9/16] overflow-hidden rounded-2xl bg-neutral-900 text-white shadow-sm ring-1 ring-border">
                {video ? (
                    <PreviewVideo
                        src={video.url}
                        className="absolute inset-0 size-full"
                        buttonClassName="top-3 right-3"
                    />
                ) : (
                    <div
                        className={cn(
                            'absolute inset-0 grid place-items-center bg-gradient-to-b text-center',
                            platform === 'facebook'
                                ? 'from-[#1b2a4a] to-neutral-900'
                                : 'from-[#3a1c3f] to-neutral-900',
                        )}
                    >
                        <div className="space-y-1.5 px-4 text-white/70">
                            <Film className="mx-auto size-6" aria-hidden />
                            <p className="text-[12px] leading-4">
                                Add a video — Reels are single-video posts.
                            </p>
                        </div>
                    </div>
                )}

                {/* Right rail: the Reel's engagement actions. */}
                <div className="absolute right-2.5 bottom-16 flex flex-col items-center gap-4">
                    {platform === 'facebook' ? (
                        <RailAction
                            icon={<ThumbsUp className="size-6" aria-hidden />}
                            label="Like"
                        />
                    ) : (
                        <RailAction
                            icon={<Heart className="size-6" aria-hidden />}
                            label="Like"
                        />
                    )}
                    <RailAction
                        icon={<MessageCircle className="size-6" aria-hidden />}
                        label="Comment"
                    />
                    {platform === 'facebook' ? (
                        <RailAction
                            icon={<Share2 className="size-6" aria-hidden />}
                            label="Share"
                        />
                    ) : (
                        <RailAction
                            icon={<Send className="size-6" aria-hidden />}
                            label="Share"
                        />
                    )}
                    {platform === 'instagram' && (
                        <RailAction
                            icon={<Bookmark className="size-6" aria-hidden />}
                            label="Save"
                        />
                    )}
                    <MoreHorizontal
                        className="size-6 drop-shadow"
                        aria-hidden
                    />
                </div>

                {/* Bottom-left: author, caption, audio row. */}
                <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent px-3 pt-10 pb-3">
                    <div className="mr-12 space-y-1.5">
                        <div className="flex items-center gap-2">
                            <Avatar className="size-6 ring-1 ring-white/70">
                                <AvatarImage
                                    src={preview.avatarUrl ?? undefined}
                                />
                                <AvatarFallback className="text-[9px] font-semibold text-foreground">
                                    {previewInitials(preview.accountName)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="truncate text-[12px] font-semibold drop-shadow">
                                {name}
                            </span>
                        </div>
                        {caption !== '' && (
                            <p className="line-clamp-2 text-[12px] leading-4 wrap-anywhere text-white/95 drop-shadow">
                                <LinkedText
                                    text={caption}
                                    platform={platform}
                                    linkExclusions={item?.linkExclusions ?? []}
                                    linkClassName={PREVIEW_ENTITY_LINK}
                                />
                            </p>
                        )}
                        <div className="flex items-center gap-1.5 text-[11px] text-white/85 drop-shadow">
                            <Music2 className="size-3" aria-hidden />
                            <span className="truncate">Original audio</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
