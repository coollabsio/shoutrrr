import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Skeleton } from '@/components/ui/skeleton';

import { initials, relativeTime } from '../helpers';
import type { ConversationItem, MessageItem } from '../types';

type Props = {
    conversation: ConversationItem;
    messages: MessageItem[];
    loading: boolean;
};

export function MessageThread({ conversation, messages, loading }: Props) {
    if (loading) {
        return (
            <div className="min-h-0 flex-1 space-y-4 overflow-x-hidden overflow-y-auto p-4">
                <Skeleton className="ml-10 h-16 w-2/3 rounded-2xl" />
                <Skeleton className="h-16 w-2/3 rounded-2xl" />
                <Skeleton className="ml-10 h-20 w-2/3 rounded-2xl" />
            </div>
        );
    }

    return (
        <div className="min-h-0 flex-1 space-y-4 overflow-x-hidden overflow-y-auto p-4">
            {messages.map((message) =>
                message.is_ours ? (
                    <div
                        key={message.id}
                        className="flex min-w-0 flex-col items-end gap-1"
                    >
                        <div className="max-w-[min(85%,28rem)] min-w-0 rounded-2xl rounded-br-sm bg-primary px-3.5 py-2.5 text-primary-foreground">
                            <p className="text-sm break-words whitespace-pre-wrap">
                                {message.text}
                            </p>
                            <div className="mt-1 text-right text-[11px] text-primary-foreground/70">
                                {message.send_status === 'sending' ? (
                                    <span className="flex items-center justify-end gap-1">
                                        <span className="size-2.5 animate-spin rounded-full border border-primary-foreground/50 border-t-transparent" />
                                        Sending…
                                    </span>
                                ) : message.send_status === 'failed' ? (
                                    <span className="text-destructive-foreground/80">
                                        Failed to send
                                    </span>
                                ) : (
                                    <>
                                        You ·{' '}
                                        {relativeTime(
                                            message.remote_created_at,
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                ) : (
                    <div key={message.id} className="flex min-w-0 gap-2.5">
                        <Avatar className="mt-0.5 size-7 shrink-0">
                            {conversation.counterpart_avatar_url ? (
                                <AvatarImage
                                    src={conversation.counterpart_avatar_url}
                                    alt=""
                                />
                            ) : null}
                            <AvatarFallback className="text-[10px]">
                                {initials(conversation)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <div className="max-w-[min(85%,28rem)] min-w-0 rounded-2xl rounded-bl-sm border bg-card px-3.5 py-2.5">
                                <p className="text-sm break-words whitespace-pre-wrap">
                                    {message.text}
                                </p>
                            </div>
                            <div className="mt-1 pl-1 text-[11px] text-muted-foreground">
                                {relativeTime(message.remote_created_at)}
                            </div>
                        </div>
                    </div>
                ),
            )}
        </div>
    );
}
