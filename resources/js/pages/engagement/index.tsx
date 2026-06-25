import { Deferred, Head } from '@inertiajs/react';
import { useState } from 'react';

import { index as engagementRoute } from '@/routes/engagement';

import { ReplyFilters } from './components/reply-filters';
import { ReplyStream } from './components/reply-stream';
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

                {/* Right pane: thread + quick reply (wired in Task 16) */}
                <div className="hidden min-h-0 flex-col md:flex">
                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                        Select a reply to see the conversation.
                    </div>
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
