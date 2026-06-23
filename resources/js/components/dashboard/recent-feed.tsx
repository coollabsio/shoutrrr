import { Link } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { useState } from 'react';

import { FilterTabs } from '@/components/common/filter-tabs';
import {
    PostRow,
    type PostRowData,
    type PostStatus,
} from '@/components/posts/post-row';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { index as postsRoute } from '@/routes/posts';

type FilterId = 'all' | 'scheduled' | 'published' | 'draft';

const FILTERS: { id: FilterId; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'scheduled', label: 'Scheduled' },
    { id: 'published', label: 'Published' },
    { id: 'draft', label: 'Drafts' },
];

/** Collapse a real status onto the dashboard's coarse filter buckets. */
function filterBucket(status: PostStatus): FilterId | null {
    switch (status) {
        case 'scheduled':
        case 'publishing':
        case 'missed':
            return 'scheduled';
        case 'published':
        case 'partial':
        case 'failed':
            return 'published';
        case 'draft':
            return 'draft';
        default:
            return null;
    }
}

export function RecentFeed({ posts }: { posts: PostRowData[] }) {
    const [tab, setTab] = useState<FilterId>('all');

    const rows = posts.filter((post) => filterBucket(post.status) !== null);
    const filtered =
        tab === 'all'
            ? rows
            : rows.filter((post) => filterBucket(post.status) === tab);

    const tabs = FILTERS.map((filter) => ({
        value: filter.id,
        label: filter.label,
        count:
            filter.id === 'all'
                ? rows.length
                : rows.filter((post) => filterBucket(post.status) === filter.id)
                      .length,
    }));

    return (
        <section className="mt-10">
            <div className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-3 px-0.5">
                <h2 className="shrink-0 text-[13px] font-semibold tracking-tight">
                    Recent posts
                </h2>
                <FilterTabs
                    tabs={tabs}
                    value={tab}
                    onChange={(v) => setTab(v as FilterId)}
                    className="order-last w-full sm:order-none sm:w-auto"
                />
                <Link
                    href={postsRoute().url}
                    className="ml-auto shrink-0 text-[12px] text-muted-foreground transition-colors hover:text-foreground"
                >
                    View all →
                </Link>
            </div>

            {filtered.length === 0 ? (
                <Empty className="py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <Inbox />
                        </EmptyMedia>
                        <EmptyTitle>
                            {tab === 'all' ? 'No posts yet' : `No ${tab} posts`}
                        </EmptyTitle>
                        <EmptyDescription>
                            {tab === 'all'
                                ? 'Compose your first post and it will show up here.'
                                : 'Nothing in this bucket yet.'}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-hidden rounded-xl border border-border">
                    {filtered.map((post) => (
                        <PostRow key={post.id} post={post} />
                    ))}
                </div>
            )}
        </section>
    );
}
