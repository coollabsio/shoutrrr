import { MoreHorizontal } from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type {
    PlatformPreview,
    PlatformPreviewItem,
} from '@/lib/compose/platform-preview';
import { LinkedText } from '@/lib/linked-text';
import { cn } from '@/lib/utils';
import type { MediaView } from '@/types/compose';

import { handleName, PREVIEW_ENTITY_LINK, previewInitials } from './helpers';
import { PreviewVideo } from './preview-video';
import {
    ThreadsCommentIcon,
    ThreadsLikeIcon,
    ThreadsRepostIcon,
    ThreadsShareIcon,
    ThreadsVerifiedIcon,
} from './threads-icons';

function ThreadsMedia({ media }: { media: MediaView[] }) {
    if (media.length === 0) {
        return null;
    }

    if (media.length === 1) {
        const only = media[0]!;

        return only.kind === 'video' ? (
            <div className="relative mt-2 aspect-square overflow-hidden rounded-xl border border-border">
                <PreviewVideo
                    src={only.url}
                    className="absolute inset-0 size-full"
                />
            </div>
        ) : (
            <img
                src={only.url}
                alt={only.alt_text ?? ''}
                className="mt-2 max-h-[430px] w-full rounded-xl border border-border object-cover"
            />
        );
    }

    // Threads presents a multi-item post as a horizontal, snap-scrolling carousel
    // that peeks the next tile.
    return (
        <div className="mt-2 flex snap-x gap-2 overflow-x-auto pb-1">
            {media.map((item) => (
                <div
                    key={item.id}
                    className="relative aspect-[3/4] w-[62%] shrink-0 snap-start overflow-hidden rounded-xl border border-border bg-muted"
                >
                    {item.kind === 'video' ? (
                        <PreviewVideo
                            src={item.url}
                            className="absolute inset-0 size-full"
                        />
                    ) : (
                        <img
                            src={item.url}
                            alt={item.alt_text ?? ''}
                            className="absolute inset-0 size-full object-cover"
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function ThreadPost({
    preview,
    item,
    isLast,
    emptyFallback,
}: {
    preview: PlatformPreview;
    item: PlatformPreviewItem;
    isLast: boolean;
    emptyFallback: string;
}) {
    const name = handleName(preview);

    return (
        <article className="relative grid grid-cols-[40px_minmax(0,1fr)] gap-3">
            {/* The thread line linking this post to the reply below it. */}
            {!isLast && (
                <div
                    className="absolute top-[46px] bottom-1 left-5 w-0.5 -translate-x-1/2 rounded-full bg-border"
                    aria-hidden
                />
            )}
            <Avatar className="z-10 size-10 bg-background">
                <AvatarImage src={preview.avatarUrl ?? undefined} />
                <AvatarFallback className="text-[11px] font-semibold">
                    {previewInitials(preview.accountName)}
                </AvatarFallback>
            </Avatar>
            <div className="min-w-0 pb-3">
                <div className="flex min-w-0 items-center gap-1.5">
                    <span className="truncate text-[14px] leading-5 font-semibold text-foreground">
                        {name}
                    </span>
                    <ThreadsVerifiedIcon className="size-3.5 shrink-0" />
                    <span className="text-[14px] leading-5 text-muted-foreground">
                        now
                    </span>
                    <MoreHorizontal
                        className="ml-auto size-4 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                </div>

                {(item.text !== '' || item.media.length === 0) && (
                    <p
                        className={cn(
                            'mt-0.5 text-[15px] leading-[1.4] wrap-anywhere whitespace-pre-wrap text-foreground',
                            item.overLimit && 'text-destructive',
                        )}
                    >
                        <LinkedText
                            text={item.text}
                            platform="threads"
                            linkExclusions={item.linkExclusions}
                            linkClassName={PREVIEW_ENTITY_LINK}
                            emptyFallback={emptyFallback}
                        />
                    </p>
                )}

                <ThreadsMedia media={item.media} />

                <div className="mt-2.5 -ml-2 flex items-center gap-1 text-foreground">
                    <span className="p-2">
                        <ThreadsLikeIcon className="size-[19px]" />
                    </span>
                    <span className="p-2">
                        <ThreadsCommentIcon className="size-[19px]" />
                    </span>
                    <span className="p-2">
                        <ThreadsRepostIcon className="size-[19px]" />
                    </span>
                    <span className="p-2">
                        <ThreadsShareIcon className="size-[19px]" />
                    </span>
                    <span className="sr-only">
                        Like · Comment · Repost · Share
                    </span>
                </div>

                {item.overLimit && (
                    <p className="text-[12px] font-medium text-destructive">
                        {item.count}/{preview.limit} characters
                    </p>
                )}
            </div>
        </article>
    );
}

/**
 * Threads renders the draft the way it actually publishes: a reply-chain thread
 * where each non-empty section becomes its own post, linked top to bottom, with
 * the media riding on the first post (single, or a peeking carousel). Every post
 * carries Threads' own chrome — the verified seal and the like/comment/repost/
 * share action bar.
 */
export function ThreadsPreview({ preview }: { preview: PlatformPreview }) {
    // Match the connector, which drops empty sections; keep at least one post so
    // a blank draft still previews with a prompt.
    const posts = preview.items.filter(
        (item) => item.text.trim() !== '' || item.media.length > 0,
    );
    const visible = posts.length > 0 ? posts : preview.items.slice(0, 1);

    return (
        <div className="p-4">
            <div className="rounded-2xl border border-border bg-background px-3 pt-3">
                {visible.map((item, index) => (
                    <ThreadPost
                        key={item.id}
                        preview={preview}
                        item={item}
                        isLast={index === visible.length - 1}
                        emptyFallback="Start writing to preview your Threads post."
                    />
                ))}
            </div>
            <p className="mt-3 text-[12px] leading-5 text-muted-foreground">
                {visible.length > 1
                    ? `Chained into a ${visible.length}-post thread, linked top to bottom.`
                    : 'Posted as one Threads post.'}
            </p>
        </div>
    );
}
