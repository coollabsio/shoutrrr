import {
    Globe,
    Heart,
    MessageCircle,
    MoreHorizontal,
    Share2,
    ThumbsUp,
    X,
} from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { PlatformPreview } from '@/lib/compose/platform-preview';
import { LinkedText } from '@/lib/linked-text';
import { cn } from '@/lib/utils';
import type { MediaView } from '@/types/compose';

import {
    facebookCollage,
    PREVIEW_ENTITY_LINK,
    previewInitials,
    previewMedia,
} from './helpers';
import { PreviewVideo } from './preview-video';
import { ReelsFrame } from './reels-frame';
import { StoryFrame } from './story-frame';

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
            <header className="flex items-center gap-2.5 px-3 pt-3 pb-2.5">
                <Avatar className="size-9">
                    <AvatarImage src={preview.avatarUrl ?? undefined} />
                    <AvatarFallback className="text-[11px] font-semibold">
                        {previewInitials(preview.accountName)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] leading-tight font-semibold text-foreground">
                        {preview.accountName}
                    </p>
                    <p className="mt-0.5 flex items-center gap-1 text-[11px] leading-tight text-muted-foreground">
                        Just now
                        <span aria-hidden>·</span>
                        <Globe className="size-3" aria-label="Public" />
                    </p>
                </div>
                <div className="flex items-center gap-1 text-muted-foreground">
                    <MoreHorizontal className="size-5" aria-hidden />
                    <X className="size-5" aria-hidden />
                </div>
            </header>

            {caption !== '' && (
                <p className="px-3 pb-2.5 text-[13px] leading-5 wrap-anywhere whitespace-pre-wrap text-foreground">
                    <LinkedText
                        text={caption}
                        platform="facebook"
                        linkExclusions={item?.linkExclusions ?? []}
                        linkClassName={PREVIEW_ENTITY_LINK}
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
 * Renders the active account's Facebook surface. The format comes from the
 * composer (per account), so the preview mirrors exactly what will publish: a
 * feed post, a Reel, or a Story.
 */
export function FacebookPreview({ preview }: { preview: PlatformPreview }) {
    return (
        <div className="p-4">
            {preview.format === 'story' ? (
                <StoryFrame preview={preview} platform="facebook" />
            ) : preview.format === 'reels' ? (
                <ReelsFrame preview={preview} platform="facebook" />
            ) : (
                <FacebookFeedPost preview={preview} />
            )}
        </div>
    );
}
