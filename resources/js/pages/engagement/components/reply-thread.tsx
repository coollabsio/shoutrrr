import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { atHandle, initials, relativeTime } from '../helpers';
import type { ReplyItem } from '../types';

type Props = {
    postExcerpt: string | null;
    postId: string | null;
    thread: ReplyItem[];
    loading: boolean;
    focusId: string | null;
};

export function ReplyThread({
    postExcerpt,
    postId,
    thread,
    loading,
    focusId,
}: Props) {
    if (loading) {
        return (
            <div className="flex-1 space-y-4 overflow-y-auto p-4">
                <Skeleton className="h-16 w-full rounded-xl" />
                <div className="space-y-3 pt-1">
                    {[0, 1].map((i) => (
                        <div key={i} className="flex gap-2.5">
                            <Skeleton className="size-7 shrink-0 rounded-full" />
                            <div className="flex-1 space-y-2 py-1">
                                <Skeleton className="h-3 w-1/3" />
                                <Skeleton className="h-3 w-3/4" />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        );
    }

    return (
        <div className="flex-1 overflow-y-auto p-4">
            {postExcerpt ? (
                <div className="mb-3 rounded-xl border bg-muted/40 p-3">
                    <div className="mb-1 flex items-center justify-between gap-2">
                        <span className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                            Your post
                        </span>
                        {postId ? (
                            <Link
                                href={ComposerController.show(postId).url}
                                className="flex items-center gap-0.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                            >
                                Open post
                                <ArrowUpRight className="size-3" />
                            </Link>
                        ) : null}
                    </div>
                    <p className="line-clamp-3 text-sm text-foreground/80">
                        {postExcerpt}
                    </p>
                </div>
            ) : null}

            <div className="flex flex-col">
                {thread.map((reply) => (
                    <article
                        key={reply.id}
                        className={cn(
                            'flex gap-2.5 rounded-lg border-l-2 border-transparent px-2.5 py-2.5',
                            reply.is_ours &&
                                'border-l-primary/60 bg-primary/[0.05]',
                            reply.id === focusId &&
                                !reply.is_ours &&
                                'bg-muted/50',
                        )}
                    >
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
                            <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                <span className="text-sm font-semibold">
                                    {reply.author_name ??
                                        atHandle(reply.author_handle)}
                                </span>
                                {reply.is_ours ? (
                                    <Badge
                                        variant="secondary"
                                        className="h-4 px-1.5 text-[10px] font-medium"
                                    >
                                        You
                                    </Badge>
                                ) : null}
                                {reply.author_name ? (
                                    <span className="text-xs text-muted-foreground">
                                        {atHandle(reply.author_handle)}
                                    </span>
                                ) : null}
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    · {relativeTime(reply.remote_created_at)}
                                </span>
                            </div>
                            <p className="mt-0.5 text-sm whitespace-pre-wrap">
                                {reply.text}
                            </p>
                        </div>
                    </article>
                ))}
            </div>
        </div>
    );
}
