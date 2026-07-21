import {
    Clapperboard,
    Heart,
    ImageIcon,
    Send,
    Share2,
    ThumbsUp,
    X,
} from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { PlatformPreview } from '@/lib/compose/platform-preview';
import { cn } from '@/lib/utils';

import { handleName, previewInitials, storyMedia } from './helpers';
import { PreviewVideo } from './preview-video';

type StoryPlatform = 'instagram' | 'facebook';

/**
 * A 9:16 story preview. Meta's Story endpoints publish a single photo or video
 * with no caption, so the frame shows only the first attachment — matching what
 * actually gets posted. Video stories autoplay muted with the shared
 * mute/unmute control.
 */
export function StoryFrame({
    preview,
    platform,
}: {
    preview: PlatformPreview;
    platform: StoryPlatform;
}) {
    const current = storyMedia(preview);
    const name =
        platform === 'facebook' ? preview.accountName : handleName(preview);

    return (
        <div className="mx-auto w-full max-w-[248px]">
            <div className="relative aspect-[9/16] overflow-hidden rounded-2xl bg-neutral-900 text-white shadow-sm ring-1 ring-border">
                {current ? (
                    current.kind === 'video' ? (
                        <PreviewVideo
                            src={current.url}
                            className="absolute inset-0 size-full"
                            buttonClassName="bottom-14 right-3"
                        />
                    ) : (
                        <img
                            src={current.url}
                            alt={current.alt_text ?? ''}
                            className="absolute inset-0 size-full object-cover"
                        />
                    )
                ) : (
                    <div
                        className={cn(
                            'absolute inset-0 grid place-items-center bg-gradient-to-b text-center',
                            platform === 'facebook'
                                ? 'from-[#1b2a4a] to-neutral-900'
                                : 'from-neutral-700 to-neutral-900',
                        )}
                    >
                        <div className="space-y-1.5 px-4 text-white/70">
                            {platform === 'facebook' ? (
                                <Clapperboard
                                    className="mx-auto size-6"
                                    aria-hidden
                                />
                            ) : (
                                <ImageIcon
                                    className="mx-auto size-6"
                                    aria-hidden
                                />
                            )}
                            <p className="text-[12px] leading-4">
                                Add a photo or video to preview your story
                            </p>
                        </div>
                    </div>
                )}

                {/* Top scrim: the story progress bar, then the author. */}
                <div className="absolute inset-x-0 top-0 bg-gradient-to-b from-black/45 to-transparent px-3 pt-2.5 pb-6">
                    <div className="h-0.5 overflow-hidden rounded-full bg-white/40">
                        <div className="h-full w-[45%] rounded-full bg-white" />
                    </div>
                    <div className="mt-2.5 flex items-center gap-2">
                        <Avatar className="size-6 ring-1 ring-white/70">
                            <AvatarImage src={preview.avatarUrl ?? undefined} />
                            <AvatarFallback className="text-[9px] font-semibold text-foreground">
                                {previewInitials(preview.accountName)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="truncate text-[12px] font-semibold drop-shadow">
                            {name}
                        </span>
                        <span className="text-[11px] text-white/80 drop-shadow">
                            now
                        </span>
                        <X className="ml-auto size-4 text-white/90 drop-shadow" />
                    </div>
                </div>

                {/* Bottom scrim: reply bar with the platform's quick actions. */}
                <div className="absolute inset-x-0 bottom-0 flex items-center gap-2 bg-gradient-to-t from-black/45 to-transparent px-3 pt-6 pb-2.5">
                    <span className="flex-1 rounded-full border border-white/60 px-3 py-1 text-[11px] text-white/90">
                        Send message
                    </span>
                    {platform === 'facebook' ? (
                        <>
                            <span className="grid size-6 place-items-center rounded-full bg-[#1877F2]">
                                <ThumbsUp
                                    className="size-3.5 text-white"
                                    aria-hidden
                                />
                            </span>
                            <Share2
                                className="size-4 text-white/90 drop-shadow"
                                aria-hidden
                            />
                        </>
                    ) : (
                        <>
                            <Heart
                                className="size-4 text-white/90 drop-shadow"
                                aria-hidden
                            />
                            <Send
                                className="size-4 text-white/90 drop-shadow"
                                aria-hidden
                            />
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
