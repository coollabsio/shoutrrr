import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

import { atHandle, initials, relativeTime } from '../helpers';
import type { ConversationItem } from '../types';

type Props = {
    conversations: ConversationItem[];
    selectedId: string | null;
    onSelect: (conversation: ConversationItem) => void;
};

export function MessageStream({ conversations, selectedId, onSelect }: Props) {
    return (
        <ul className="flex flex-col">
            {conversations.map((conversation) => {
                const selected = selectedId === conversation.id;
                const unread = conversation.unread_count > 0;

                return (
                    <li key={conversation.id}>
                        <button
                            type="button"
                            id={`message-conversation-${conversation.id}`}
                            onClick={() => onSelect(conversation)}
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
                                    {conversation.counterpart_avatar_url ? (
                                        <AvatarImage
                                            src={
                                                conversation.counterpart_avatar_url
                                            }
                                            alt=""
                                        />
                                    ) : null}
                                    <AvatarFallback className="text-[11px]">
                                        {initials(conversation)}
                                    </AvatarFallback>
                                </Avatar>
                                <span className="absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full bg-background text-muted-foreground ring-1 ring-border">
                                    <PlatformGlyph
                                        platform={conversation.platform}
                                        size={9}
                                    />
                                </span>
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex items-baseline gap-1.5">
                                    <span
                                        className={cn(
                                            'min-w-0 truncate text-sm',
                                            unread
                                                ? 'font-semibold text-foreground'
                                                : 'font-medium text-foreground/90',
                                        )}
                                    >
                                        {conversation.counterpart_name ??
                                            atHandle(
                                                conversation.counterpart_handle,
                                            )}
                                    </span>
                                    {conversation.counterpart_name ? (
                                        <span className="min-w-0 truncate text-xs text-muted-foreground">
                                            {atHandle(
                                                conversation.counterpart_handle,
                                            )}
                                        </span>
                                    ) : null}
                                    <span className="ml-auto shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                        {relativeTime(
                                            conversation.last_message_at,
                                        )}
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
                                    {conversation.last_message_preview}
                                </p>

                                <div className="mt-1 flex items-center gap-1.5 text-[11px] text-muted-foreground/70">
                                    {unread ? (
                                        <Badge
                                            variant="default"
                                            className="h-4 min-w-4 justify-center px-1 text-[10px] tabular-nums"
                                        >
                                            {conversation.unread_count > 99
                                                ? '99+'
                                                : conversation.unread_count}
                                        </Badge>
                                    ) : null}
                                    {conversation.account_handle ? (
                                        <span className="truncate">
                                            via {conversation.account_handle}
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
