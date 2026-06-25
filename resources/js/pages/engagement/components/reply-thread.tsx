import type { ReplyItem } from '../types';

type Props = {
    postExcerpt: string | null;
    thread: ReplyItem[];
    loading: boolean;
};

export function ReplyThread({ postExcerpt, thread, loading }: Props) {
    if (loading) {
        return (
            <div className="space-y-3 p-4">
                {[0, 1, 2].map((i) => (
                    <div
                        key={i}
                        className="h-12 animate-pulse rounded bg-muted"
                    />
                ))}
            </div>
        );
    }

    return (
        <div className="flex-1 space-y-3 overflow-y-auto p-4">
            {postExcerpt ? (
                <div className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                    Your post: {postExcerpt}
                </div>
            ) : null}

            {thread.map((reply) => (
                <div
                    key={reply.id}
                    className={`rounded-md border p-3 ${reply.status === 'archived' ? 'opacity-60' : ''} ${reply.is_read ? '' : 'border-primary/40'}`}
                >
                    <div className="mb-1 flex items-center gap-2">
                        <span className="text-sm font-medium">
                            {reply.author_name ?? reply.author_handle}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {reply.author_handle}
                        </span>
                    </div>
                    <p className="text-sm whitespace-pre-wrap">{reply.text}</p>
                </div>
            ))}
        </div>
    );
}
