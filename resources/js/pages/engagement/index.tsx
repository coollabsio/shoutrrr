import { Deferred, Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import {
    archive as archiveRoute,
    index as engagementRoute,
    respond as respondRoute,
    thread as threadRoute,
} from '@/routes/engagement';

import { QuickReplyBox } from './components/quick-reply-box';
import { ReplyFilters } from './components/reply-filters';
import { ReplyStream } from './components/reply-stream';
import { ReplyThread } from './components/reply-thread';
import type { AccountFacet, EngagementFilters, ReplyItem } from './types';

type PageProps = {
    replies?: { data: ReplyItem[] };
    filters: EngagementFilters;
    facets: { accounts: AccountFacet[] };
};

function StreamSkeleton() {
    return (
        <div className="space-y-3 p-3">
            {[0, 1, 2, 3, 4].map((i) => (
                <div key={i} className="flex animate-pulse gap-3">
                    <div className="size-9 rounded-full bg-muted" />
                    <div className="flex-1 space-y-2">
                        <div className="h-3 w-1/3 rounded bg-muted" />
                        <div className="h-3 w-2/3 rounded bg-muted" />
                    </div>
                </div>
            ))}
        </div>
    );
}

type RightPaneProps = {
    selected: ReplyItem | null;
    onArchived: () => void;
};

function RightPane({ selected, onArchived }: RightPaneProps) {
    const [thread, setThread] = useState<ReplyItem[]>([]);
    const [postExcerpt, setPostExcerpt] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const selectedId = selected?.id ?? null;

    useEffect(() => {
        if (!selectedId) {
            setThread([]);
            setPostExcerpt(null);
            return;
        }
        setLoading(true);
        fetch(threadRoute(selectedId).url, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then(
                (data: {
                    post_excerpt: string | null;
                    thread: ReplyItem[];
                }) => {
                    setPostExcerpt(data.post_excerpt);
                    setThread(data.thread);
                },
            )
            .catch(() => {
                setPostExcerpt(null);
                setThread([]);
            })
            .finally(() => setLoading(false));
    }, [selectedId]);

    if (!selected) {
        return (
            <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                Select a reply to see the conversation.
            </div>
        );
    }

    async function send(text: string) {
        await new Promise<void>((resolve, reject) => {
            router.post(
                respondRoute(selected!.id).url,
                { text },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setThread((prev) => [
                            ...prev,
                            {
                                ...selected!,
                                id: `temp-${Date.now()}`,
                                text,
                                is_read: true,
                                status: 'responded',
                                author_handle: 'you',
                                author_name: 'You',
                            },
                        ]);
                        resolve();
                    },
                    onError: () => reject(new Error('send failed')),
                },
            );
        });
    }

    return (
        <div className="flex h-full flex-col">
            <div className="flex items-center justify-between border-b p-3">
                <span className="text-sm font-medium">Conversation</span>
                <button
                    type="button"
                    onClick={() =>
                        router.post(
                            archiveRoute(selected.id).url,
                            {},
                            { preserveScroll: true, onSuccess: onArchived },
                        )
                    }
                    className="text-xs text-muted-foreground hover:text-foreground"
                >
                    Archive
                </button>
            </div>
            <ReplyThread
                postExcerpt={postExcerpt}
                thread={thread}
                loading={loading}
            />
            <QuickReplyBox
                platform={selected.platform}
                maxLength={selected.account_max_text_length ?? undefined}
                onSend={send}
            />
        </div>
    );
}

export default function EngagementIndex({
    replies,
    filters,
    facets,
}: PageProps) {
    const [selected, setSelected] = useState<ReplyItem | null>(null);

    const items = replies?.data ?? [];

    return (
        <>
            <Head title="Engagement" />

            <div className="grid h-full grid-cols-1 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                {/* Left pane: filters + reply stream */}
                <div className="flex min-h-0 flex-col border-r">
                    <ReplyFilters
                        filters={filters}
                        accounts={facets.accounts}
                    />
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <Deferred data="replies" fallback={<StreamSkeleton />}>
                            <ReplyStream
                                replies={items}
                                selectedId={selected?.id ?? null}
                                onSelect={setSelected}
                            />
                        </Deferred>
                    </div>
                </div>

                {/* Right pane: thread + quick reply */}
                <div className="hidden min-h-0 flex-col md:flex">
                    <RightPane
                        selected={selected}
                        onArchived={() => setSelected(null)}
                    />
                </div>
            </div>
        </>
    );
}

EngagementIndex.layout = {
    breadcrumbs: [
        {
            title: 'Engagement',
            href: engagementRoute().url,
        },
    ],
};
