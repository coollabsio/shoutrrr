import {
    Clapperboard,
    Globe,
    Heart,
    MessageCircle,
    MoreHorizontal,
    Share2,
    SquarePlay,
    ThumbsUp,
    X,
} from 'lucide-react';
import { useState } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { PlatformPreview } from '@/lib/compose/platform-preview';
import { LinkedText } from '@/lib/linked-text';
import { cn } from '@/lib/utils';
import type { MediaView } from '@/types/compose';

import { PreviewFormatToggle } from './format-toggle';
import {
    facebookCollage,
    previewInitials,
    previewMedia,
    storyMedia,
} from './helpers';
import { PreviewVideo } from './preview-video';

type FacebookFormat = 'feed' | 'story';

function MediaTile({
    media,
    className,
    overlay,
}: {
    media: MediaView;
    className?: string;
    overlay?: number;
}) {
    return (
        <div
            className={cn('relative overflow-hidden bg-neutral-200', className)}
        >
            {media.kind === 'video' ? (
                <PreviewVideo
                    src={media.url}
                    className="absolute inset-0 size-full"
                />
            ) : (
                <img
                    src={media.url}
                    alt={media.alt_text ?? ''}
                    className="absolute inset-0 size-full object-cover"
                />
            )}
            {overlay !== undefined && overlay > 0 && (
                <div className="absolute inset-0 grid place-items-center bg-black/45 text-2xl font-semibold text-white">
                    +{overlay}
                </div>
            )}
        </div>
    );
}

/**
 * Facebook's native album mosaic. One photo shows full width; multiple photos are
 * arranged by count (2 split, 3 as one-over-two, 4 as a 2×2, 5+ as two-over-three
 * with a "+N" overlay on the last tile), matching the Facebook feed.
 */
function FacebookMedia({ media }: { media: MediaView[] }) {
    if (media.length === 0) {
        return null;
    }

    if (media.length === 1) {
        const only = media[0]!;

        return <MediaTile media={only} className="aspect-[4/3] w-full" />;
    }

    const layout = facebookCollage(media.length);
    const visible = media.slice(0, layout.tiles.length);

    return (
        <div className={cn('w-full bg-background', layout.container)}>
            {visible.map((item, tile) => {
                const isLast = tile === visible.length - 1;

                return (
                    <MediaTile
                        key={item.id}
                        media={item}
                        className={cn('relative', layout.tiles[tile])}
                        overlay={isLast ? layout.overflow : 0}
                    />
                );
            })}
        </div>
    );
}

function ReactionCluster() {
    return (
        <span className="flex items-center -space-x-1" aria-hidden>
            <span className="grid size-4 place-items-center rounded-full bg-[#1877F2] ring-2 ring-background">
                <ThumbsUp className="size-2.5 text-white" fill="currentColor" />
            </span>
            <span className="grid size-4 place-items-center rounded-full bg-[#F33E58] ring-2 ring-background">
                <Heart className="size-2.5 text-white" fill="currentColor" />
            </span>
        </span>
    );
}

function FacebookFeedPost({ preview }: { preview: PlatformPreview }) {
    const media = previewMedia(preview);
    const item = preview.items[0];
    const caption = item?.text ?? '';

    return (
        <article className="mx-auto w-full max-w-[400px] overflow-hidden rounded-xl border border-border bg-background">
            <header className="flex items-center gap-2.5 px-3 pt-3">
                <Avatar className="size-9">
                    <AvatarImage src={preview.avatarUrl ?? undefined} />
                    <AvatarFallback className="text-[11px] font-semibold">
                        {previewInitials(preview.accountName)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-semibold text-foreground">
                        {preview.accountName}
                    </p>
                    <p className="flex items-center gap-1 text-[11px] text-muted-foreground">
                        Just now
                        <span aria-hidden>·</span>
                        <Globe className="size-3" aria-label="Public" />
                    </p>
                </div>
                <MoreHorizontal
                    className="size-5 text-muted-foreground"
                    aria-hidden
                />
                <X className="size-5 text-muted-foreground" aria-hidden />
            </header>

            {caption !== '' && (
                <p className="px-3 py-2 text-[13px] leading-5 wrap-anywhere whitespace-pre-wrap text-foreground">
                    <LinkedText
                        text={caption}
                        platform="facebook"
                        linkExclusions={item?.linkExclusions ?? []}
                    />
                </p>
            )}

            <FacebookMedia media={media} />

            <div className="flex items-center justify-between px-3 py-2 text-[12px] text-muted-foreground">
                <span className="flex items-center gap-1.5">
                    <ReactionCluster />
                </span>
                <span>Comments · Shares</span>
            </div>

            <div className="mx-3 grid grid-cols-3 border-t border-border py-1 text-[13px] font-medium text-muted-foreground">
                <span className="flex items-center justify-center gap-1.5 py-1">
                    <ThumbsUp className="size-4" aria-hidden />
                    Like
                </span>
                <span className="flex items-center justify-center gap-1.5 py-1">
                    <MessageCircle className="size-4" aria-hidden />
                    Comment
                </span>
                <span className="flex items-center justify-center gap-1.5 py-1">
                    <Share2 className="size-4" aria-hidden />
                    Share
                </span>
            </div>
        </article>
    );
}

/**
 * A 9:16 Facebook Story frame. Same safe-zone concerns as an Instagram story —
 * top progress bar + author header, bottom reply bar — with Facebook's blue
 * accent. A Story publishes a single photo or video with no caption.
 */
function FacebookStory({ preview }: { preview: PlatformPreview }) {
    const media = storyMedia(preview);

    return (
        <div className="mx-auto w-full max-w-[248px]">
            <div className="relative aspect-[9/16] overflow-hidden rounded-2xl bg-neutral-900 text-white shadow-sm ring-1 ring-border">
                {media ? (
                    media.kind === 'video' ? (
                        <PreviewVideo
                            src={media.url}
                            className="absolute inset-0 size-full"
                            buttonClassName="bottom-14 right-3"
                        />
                    ) : (
                        <img
                            src={media.url}
                            alt={media.alt_text ?? ''}
                            className="absolute inset-0 size-full object-cover"
                        />
                    )
                ) : (
                    <div className="absolute inset-0 grid place-items-center bg-gradient-to-b from-[#1b2a4a] to-neutral-900 text-center">
                        <div className="space-y-1.5 px-4 text-white/70">
                            <Clapperboard
                                className="mx-auto size-6"
                                aria-hidden
                            />
                            <p className="text-[12px] leading-4">
                                Add one photo or video to preview your story
                            </p>
                        </div>
                    </div>
                )}

                <div className="absolute inset-x-0 top-0 bg-gradient-to-b from-black/45 to-transparent px-3 pt-2.5 pb-6">
                    <div className="h-0.5 w-full overflow-hidden rounded-full bg-white/40">
                        <div className="h-full w-1/3 rounded-full bg-white" />
                    </div>
                    <div className="mt-2.5 flex items-center gap-2">
                        <Avatar className="size-6 ring-1 ring-white/70">
                            <AvatarImage src={preview.avatarUrl ?? undefined} />
                            <AvatarFallback className="text-[9px] font-semibold text-foreground">
                                {previewInitials(preview.accountName)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="truncate text-[12px] font-semibold drop-shadow">
                            {preview.accountName}
                        </span>
                        <span className="text-[11px] text-white/80 drop-shadow">
                            now
                        </span>
                        <X className="ml-auto size-4 text-white/90 drop-shadow" />
                    </div>
                </div>

                <div className="absolute inset-x-0 bottom-0 flex items-center gap-2 bg-gradient-to-t from-black/45 to-transparent px-3 pt-6 pb-2.5">
                    <span className="flex-1 rounded-full border border-white/60 px-3 py-1 text-[11px] text-white/90">
                        Send message
                    </span>
                    <span className="grid size-6 place-items-center rounded-full bg-[#1877F2]">
                        <ThumbsUp className="size-3.5 text-white" aria-hidden />
                    </span>
                    <Share2
                        className="size-4 text-white/90 drop-shadow"
                        aria-hidden
                    />
                </div>
            </div>
            <p className="mt-3 text-center text-[12px] leading-5 text-muted-foreground">
                9:16 · 1080×1920 · keep key content clear of the top and bottom
                bars. Captions aren&apos;t shown on stories.
            </p>
        </div>
    );
}

const FORMAT_OPTIONS = [
    { value: 'feed', label: 'Feed', icon: SquarePlay },
    { value: 'story', label: 'Story', icon: Clapperboard },
] as const;

export function FacebookPreview({ preview }: { preview: PlatformPreview }) {
    const [format, setFormat] = useState<FacebookFormat>('feed');

    return (
        <div className="space-y-4 p-4">
            <div className="flex justify-center">
                <PreviewFormatToggle
                    value={format}
                    onChange={setFormat}
                    options={[...FORMAT_OPTIONS]}
                    ariaLabel="Facebook format"
                />
            </div>
            {format === 'story' ? (
                <FacebookStory preview={preview} />
            ) : (
                <FacebookFeedPost preview={preview} />
            )}
        </div>
    );
}
