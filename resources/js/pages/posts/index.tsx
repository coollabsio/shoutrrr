import { Head, InfiniteScroll, Link, router, usePage } from '@inertiajs/react';
import { Filter, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { PostRow, type PostRowData } from '@/pages/posts/post-row';
import { dashboard } from '@/routes';
import { index as postsRoute } from '@/routes/posts';

type StatusTab = 'all' | 'scheduled' | 'draft' | 'published' | 'missed';

type Props = {
    posts: { data: PostRowData[] };
    filters: { status: string; set: string; platform: string; q: string };
    sets: { id: string; name: string }[];
};

const STATUS_TABS: { value: StatusTab; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'draft', label: 'Drafts' },
    { value: 'published', label: 'Published' },
    { value: 'missed', label: 'Missed' },
];

const PLATFORM_OPTIONS: { value: string; label: string }[] = [
    { value: 'x', label: 'X' },
    { value: 'bluesky', label: 'Bluesky' },
    { value: 'linkedin', label: 'LinkedIn' },
];

function emptyMessage(status: string, hasActiveFilter: boolean): string {
    if (hasActiveFilter) {
        return 'No posts match your search or filters.';
    }
    if (status === 'scheduled') return 'No scheduled posts.';
    if (status === 'draft') return 'No drafts yet. Start composing.';
    if (status === 'published') return 'No published posts yet.';
    return 'No posts yet. Start composing.';
}

export default function PostsIndex({ posts, filters, sets }: Props) {
    const page = usePage();
    const scrollProps = page.scrollProps?.['posts'];
    const hasMore = !!scrollProps?.nextPage;

    const [localQ, setLocalQ] = useState(filters.q);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Keep localQ in sync when server filters change (e.g. back/forward nav)
    useEffect(() => {
        setLocalQ(filters.q);
    }, [filters.q]);

    function applyFilters(next: {
        status?: string;
        set?: string;
        platform?: string;
        q?: string;
    }) {
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        router.visit(postsRoute().url, {
            data: {
                status: next.status ?? filters.status,
                set: next.set ?? filters.set,
                platform: next.platform ?? filters.platform,
                q: next.q ?? filters.q,
            },
            only: ['posts', 'filters'],
            reset: ['posts'],
            replace: true,
            preserveScroll: false,
        });
    }

    function handleQChange(value: string) {
        setLocalQ(value);
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        debounceRef.current = setTimeout(() => {
            applyFilters({ q: value });
        }, 250);
    }

    function clearQ() {
        setLocalQ('');
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        applyFilters({ q: '' });
    }

    function handleStatusChange(status: StatusTab) {
        applyFilters({ status });
    }

    // Platform is a single-select checkbox toggle (click again to deselect)
    function handlePlatformToggle(platform: string) {
        applyFilters({
            platform: filters.platform === platform ? '' : platform,
        });
    }

    function handleSetChange(setId: string) {
        applyFilters({ set: setId === 'all' ? '' : setId });
    }

    const activeFilterCount =
        (filters.platform !== '' ? 1 : 0) + (filters.set !== '' ? 1 : 0);

    const hasActiveFilter =
        activeFilterCount > 0 || filters.q !== '' || filters.status !== 'all';

    const items = posts.data;

    return (
        <>
            <Head title="Posts" />

            <div className="flex flex-col gap-0">
                {/* Command bar */}
                <div className="sticky top-0 z-10 flex items-center gap-2 border-b border-border bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                    <h2 className="mr-2 text-[15px] font-semibold tracking-tight">
                        Posts
                    </h2>

                    {/* Search */}
                    <div className="relative max-w-xs flex-1">
                        <Input
                            placeholder="Search posts…"
                            value={localQ}
                            onChange={(e) => handleQChange(e.target.value)}
                            className="h-8 pr-7 text-sm"
                        />
                        {localQ && (
                            <button
                                type="button"
                                aria-label="Clear search"
                                onClick={clearQ}
                                className="absolute inset-y-0 right-2 flex items-center text-muted-foreground hover:text-foreground"
                            >
                                <X className="size-3.5" />
                            </button>
                        )}
                    </div>

                    {/* Filter dropdown */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8 gap-1.5"
                            >
                                <Filter className="size-3.5" />
                                Filter
                                {activeFilterCount > 0 && (
                                    <Badge
                                        variant="secondary"
                                        className="ml-0.5 h-4 rounded-full px-1.5 text-[10px]"
                                    >
                                        {activeFilterCount}
                                    </Badge>
                                )}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                                Platform
                            </DropdownMenuLabel>
                            {PLATFORM_OPTIONS.map((opt) => (
                                <DropdownMenuCheckboxItem
                                    key={opt.value}
                                    checked={filters.platform === opt.value}
                                    onSelect={(e) => {
                                        e.preventDefault();
                                        handlePlatformToggle(opt.value);
                                    }}
                                >
                                    {opt.label}
                                </DropdownMenuCheckboxItem>
                            ))}
                            {sets.length > 0 && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                                        Set
                                    </DropdownMenuLabel>
                                    <DropdownMenuRadioGroup
                                        value={filters.set || 'all'}
                                        onValueChange={handleSetChange}
                                    >
                                        <DropdownMenuRadioItem value="all">
                                            All sets
                                        </DropdownMenuRadioItem>
                                        {sets.map((s) => (
                                            <DropdownMenuRadioItem
                                                key={s.id}
                                                value={s.id}
                                            >
                                                {s.name}
                                            </DropdownMenuRadioItem>
                                        ))}
                                    </DropdownMenuRadioGroup>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <div className="ml-auto">
                        <Button asChild size="sm" className="h-8">
                            <Link href={dashboard().url}>New post</Link>
                        </Button>
                    </div>
                </div>

                {/* Status tabs */}
                <div className="flex items-center gap-1 border-b border-border px-4 py-2">
                    {STATUS_TABS.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => handleStatusChange(tab.value)}
                            className={cn(
                                'rounded-full px-3 py-1 text-[13px] transition-colors',
                                filters.status === tab.value
                                    ? 'bg-muted font-medium text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                {/* List body */}
                <div className="p-4">
                    {items.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 py-12">
                            <p className="text-center text-sm text-muted-foreground">
                                {emptyMessage(filters.status, hasActiveFilter)}
                            </p>
                            {!hasActiveFilter && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={dashboard().url}>New post</Link>
                                </Button>
                            )}
                        </div>
                    ) : (
                        <InfiniteScroll
                            data="posts"
                            next={({ loading }) =>
                                loading ? (
                                    <Skeleton className="h-12 w-full" />
                                ) : null
                            }
                        >
                            <div className="rounded-xl border border-border">
                                {items.map((post) => (
                                    <PostRow key={post.id} post={post} />
                                ))}
                            </div>
                        </InfiniteScroll>
                    )}

                    {!hasMore && items.length > 0 && (
                        <p className="mt-4 text-center text-xs text-muted-foreground">
                            All posts loaded.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

PostsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Posts',
            href: postsRoute().url,
        },
    ],
};
