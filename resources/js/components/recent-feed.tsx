import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { cn } from '@/lib/utils';
import {
    PostRow,
    type PostRowData,
    type PostStatus,
} from '@/pages/posts/post-row';
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

    return (
        <section className="mt-10">
            <div className="mb-3 flex items-baseline gap-3 px-0.5">
                <h2 className="text-[13px] font-semibold tracking-tight">
                    Recent posts
                </h2>
                <div className="flex gap-1">
                    {FILTERS.map((filter) => (
                        <button
                            key={filter.id}
                            type="button"
                            onClick={() => setTab(filter.id)}
                            className={cn(
                                'rounded-full px-2.5 py-1 text-[12px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                                tab === filter.id &&
                                    'bg-muted font-medium text-foreground',
                            )}
                        >
                            {filter.label}
                        </button>
                    ))}
                </div>
                <Link
                    href={postsRoute().url}
                    className="ml-auto text-[12px] text-muted-foreground transition-colors hover:text-foreground"
                >
                    View all →
                </Link>
            </div>

            {filtered.length === 0 ? (
                <p className="rounded-xl border border-border py-12 text-center text-[13px] text-muted-foreground">
                    {tab === 'all' ? 'No posts yet.' : `No ${tab} posts.`}
                </p>
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
