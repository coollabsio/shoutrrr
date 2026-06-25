import { CheckCheck } from 'lucide-react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

import { atHandle, initials, relativeTime } from '../helpers';
import type { ReplyItem } from '../types';

type Props = {
    replies: ReplyItem[];
    selectedId: string | null;
    onSelect: (reply: ReplyItem) => void;
};

export function ReplyStream({ replies, selectedId, onSelect }: Props) {
    return (
        <ul className="flex flex-col">
            {replies.map((reply) => {
                const selected = selectedId === reply.id;
                const unread = !reply.is_read;

                return (
                    <li key={reply.id}>
                        <button
                            type="button"
                            onClick={() => onSelect(reply)}
                            aria-current={selected}
                            className={cn(
                                'flex w-full gap-3 border-l-2 border-transparent px-3 py-3 text-left transition-colors',
                                'hover:bg-muted/60',
                                unread && 'border-l-primary bg-primary/[0.04]',
                                selected && 'bg-muted hover:bg-muted',
                            )}
                        >
                            <div className="relative shrink-0">
                                <Avatar className="size-9">
                                    {reply.author_avatar_url ? (
                                        <AvatarImage
                                            src={reply.author_avatar_url}
                                            alt=""
                                        />
                                    ) : null}
                                    <AvatarFallback className="text-[11px]">
                                        {initials(reply)}
                                    </AvatarFallback>
                                </Avatar>
                                <span className="absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full bg-background text-muted-foreground ring-1 ring-border">
                                    <PlatformGlyph
                                        platform={reply.platform}
                                        size={9}
                                    />
                                </span>
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex items-baseline gap-1.5">
                                    <span
                                        className={cn(
                                            'truncate text-sm',
                                            unread
                                                ? 'font-semibold text-foreground'
                                                : 'font-medium text-foreground/90',
                                        )}
                                    >
                                        {reply.author_name ??
                                            atHandle(reply.author_handle)}
                                    </span>
                                    {reply.author_name ? (
                                        <span className="truncate text-xs text-muted-foreground">
                                            {atHandle(reply.author_handle)}
                                        </span>
                                    ) : null}
                                    <span className="ml-auto shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                        {relativeTime(reply.remote_created_at)}
                                    </span>
                                </div>

                                <p
                                    className={cn(
                                        'mt-0.5 line-clamp-2 text-sm',
                                        unread
                                            ? 'text-foreground/80'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {reply.text}
                                </p>

                                <div className="mt-1 flex items-center gap-1.5 text-[11px] text-muted-foreground/70">
                                    {reply.status === 'responded' ? (
                                        <span className="flex items-center gap-1 font-medium text-primary">
                                            <CheckCheck className="size-3" />
                                            Replied
                                        </span>
                                    ) : null}
                                    {reply.post_excerpt ? (
                                        <span className="truncate">
                                            on “{reply.post_excerpt}”
                                        </span>
                                    ) : null}
                                </div>
                            </div>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
