import { ExternalLink, Heart, Trash2 } from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Skeleton } from '@/components/ui/skeleton';
import { platformLabel, postPermalink } from '@/lib/posts/permalink';
import { cn } from '@/lib/utils';

import { atHandle, initials, relativeTime } from '../helpers';
import type { ReplyItem } from '../types';

type Props = {
    postExcerpt: string | null;
    thread: ReplyItem[];
    loading: boolean;
    onToggleLike: (reply: ReplyItem) => void;
    onDelete: (reply: ReplyItem) => void;
};

const actionButton =
    'flex items-center justify-center rounded-md p-1 text-muted-foreground transition-colors';

export function ReplyThread({
    postExcerpt,
    thread,
    loading,
    onToggleLike,
    onDelete,
}: Props) {
    if (loading) {
        return (
            <div className="min-h-0 flex-1 space-y-4 overflow-x-hidden overflow-y-auto p-4">
                <Skeleton className="h-16 w-full rounded-xl" />
                <Skeleton className="ml-10 h-20 w-2/3 rounded-2xl" />
                <Skeleton className="h-20 w-2/3 rounded-2xl" />
            </div>
        );
    }

    return (
        <div className="min-h-0 flex-1 space-y-4 overflow-x-hidden overflow-y-auto p-4">
            {postExcerpt ? (
                <div className="rounded-xl border bg-muted/40 p-3">
                    <div className="mb-1 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                        Your post
                    </div>
                    <p className="line-clamp-3 text-sm break-words text-foreground/80">
                        {postExcerpt}
                    </p>
                </div>
            ) : null}

            {thread.map((reply) => {
                const settled =
                    reply.send_status !== 'sending' &&
                    reply.send_status !== 'failed';
                const permalink = settled
                    ? postPermalink(
                          reply.platform,
                          reply.author_handle,
                          reply.remote_reply_id,
                      )
                    : null;

                return reply.is_ours ? (
                    <div
                        key={reply.id}
                        className="group flex min-w-0 flex-col items-end gap-1"
                    >
                        <div className="max-w-[min(85%,28rem)] min-w-0 rounded-2xl rounded-br-sm bg-primary px-3.5 py-2.5 text-primary-foreground">
                            <p className="text-sm break-words whitespace-pre-wrap">
                                {reply.text}
                            </p>
                            <div className="mt-1 text-right text-[11px] text-primary-foreground/70">
                                {reply.send_status === 'sending' ? (
                                    <span className="flex items-center justify-end gap-1">
                                        <span className="size-2.5 animate-spin rounded-full border border-primary-foreground/50 border-t-transparent" />
                                        Sending…
                                    </span>
                                ) : reply.send_status === 'failed' ? (
                                    <span className="text-destructive-foreground/80">
                                        Failed to send
                                    </span>
                                ) : (
                                    <>
                                        You ·{' '}
                                        {relativeTime(reply.remote_created_at)}
                                    </>
                                )}
                            </div>
                        </div>
                        {settled ? (
                            <div className="flex items-center gap-0.5 pr-1 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                                {permalink ? (
                                    <a
                                        href={permalink}
                                        target="_blank"
                                        rel="noreferrer"
                                        aria-label={`Open on ${platformLabel(reply.platform)}`}
                                        className={cn(
                                            actionButton,
                                            'hover:text-foreground',
                                        )}
                                    >
                                        <ExternalLink className="size-3.5" />
                                    </a>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() => onDelete(reply)}
                                    aria-label="Delete reply"
                                    className={cn(
                                        actionButton,
                                        'hover:text-destructive',
                                    )}
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            </div>
                        ) : null}
                    </div>
                ) : (
                    <div key={reply.id} className="group flex min-w-0 gap-2.5">
                        <Avatar className="mt-0.5 size-7 shrink-0">
                            {reply.author_avatar_url ? (
                                <AvatarImage
                                    src={reply.author_avatar_url}
                                    alt=""
                                />
                            ) : null}
                            <AvatarFallback className="text-[10px]">
                                {initials(reply)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <div
                                className={cn(
                                    'max-w-[min(85%,28rem)] min-w-0 rounded-2xl rounded-bl-sm border bg-card px-3.5 py-2.5',
                                    !reply.is_read && 'border-primary/40',
                                )}
                            >
                                <div className="mb-0.5 flex min-w-0 items-baseline gap-1.5">
                                    <span className="min-w-0 truncate text-xs font-semibold">
                                        {reply.author_name ??
                                            atHandle(reply.author_handle)}
                                    </span>
                                    {reply.author_name ? (
                                        <span className="min-w-0 truncate text-[11px] text-muted-foreground">
                                            {atHandle(reply.author_handle)}
                                        </span>
                                    ) : null}
                                    <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                        ·{' '}
                                        {relativeTime(reply.remote_created_at)}
                                    </span>
                                </div>
                                <p className="text-sm break-words whitespace-pre-wrap">
                                    {reply.text}
                                </p>
                            </div>
                            <div
                                className={cn(
                                    'mt-1 flex items-center gap-0.5 pl-1 transition-opacity group-hover:opacity-100 focus-within:opacity-100',
                                    reply.is_liked
                                        ? 'opacity-100'
                                        : 'opacity-0',
                                )}
                            >
                                <button
                                    type="button"
                                    onClick={() => onToggleLike(reply)}
                                    disabled={!reply.can_like}
                                    title={
                                        reply.can_like
                                            ? undefined
                                            : `${platformLabel(reply.platform)} does not support liking replies`
                                    }
                                    aria-label={
                                        reply.is_liked ? 'Unlike' : 'Like'
                                    }
                                    aria-pressed={reply.is_liked}
                                    className={cn(
                                        actionButton,
                                        'disabled:cursor-not-allowed disabled:opacity-40',
                                        reply.is_liked
                                            ? 'text-rose-500'
                                            : 'hover:text-foreground',
                                    )}
                                >
                                    <Heart
                                        className={cn(
                                            'size-3.5',
                                            reply.is_liked && 'fill-current',
                                        )}
                                    />
                                </button>
                                {permalink ? (
                                    <a
                                        href={permalink}
                                        target="_blank"
                                        rel="noreferrer"
                                        aria-label={`Open on ${platformLabel(reply.platform)}`}
                                        className={cn(
                                            actionButton,
                                            'hover:text-foreground',
                                        )}
                                    >
                                        <ExternalLink className="size-3.5" />
                                    </a>
                                ) : null}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
