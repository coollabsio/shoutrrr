import type { ReplyItem } from '../types';

type Props = {
    replies: ReplyItem[];
    selectedId: string | null;
    onSelect: (reply: ReplyItem) => void;
};

export function ReplyStream({ replies, selectedId, onSelect }: Props) {
    if (replies.length === 0) {
        return (
            <div className="p-6 text-sm text-muted-foreground">
                No replies yet. New replies appear here within ~15 minutes of
                being posted.
            </div>
        );
    }

    return (
        <ul className="divide-y">
            {replies.map((reply) => (
                <li key={reply.id}>
                    <button
                        type="button"
                        onClick={() => onSelect(reply)}
                        className={`flex w-full gap-3 p-3 text-left hover:bg-muted/50 ${selectedId === reply.id ? 'bg-muted' : ''}`}
                    >
                        {reply.author_avatar_url ? (
                            <img
                                src={reply.author_avatar_url}
                                alt=""
                                className="size-9 shrink-0 rounded-full"
                            />
                        ) : (
                            <div className="size-9 shrink-0 rounded-full bg-muted" />
                        )}
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                {!reply.is_read ? (
                                    <span className="size-2 shrink-0 rounded-full bg-primary" />
                                ) : null}
                                <span className="truncate text-sm font-medium">
                                    {reply.author_name ?? reply.author_handle}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {reply.account_handle}
                                </span>
                            </div>
                            <p className="truncate text-sm text-muted-foreground">
                                {reply.text}
                            </p>
                            {reply.post_excerpt ? (
                                <p className="mt-0.5 truncate text-xs text-muted-foreground/70">
                                    on: {reply.post_excerpt}
                                </p>
                            ) : null}
                        </div>
                    </button>
                </li>
            ))}
        </ul>
    );
}
