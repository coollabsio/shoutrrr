import {
    Deferred,
    Head,
    InfiniteScroll,
    Link,
    router,
    usePage,
} from '@inertiajs/react';
import { Filter, Inbox, Search, SearchX, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { FilterTabs } from '@/components/common/filter-tabs';
import { PostRow, type PostRowData } from '@/components/posts/post-row';
import { PostListSkeleton } from '@/components/skeletons/post-list-skeleton';
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
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';
import { index as postsRoute } from '@/routes/posts';

type StatusTab = 'all' | 'scheduled' | 'draft' | 'published' | 'missed';

type Props = {
    posts?: { data: PostRowData[] };
    filters: { status: string; set: string; platform: string; q: string };
    sets: { id: string; name: string }[];
    counts: Record<StatusTab, number>;
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

function FilterChip({
    label,
    onClear,
}: {
    label: string;
    onClear: () => void;
}) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-muted py-0.5 pr-1 pl-2 text-[12px] font-medium text-foreground">
            {label}
            <button
                type="button"
                aria-label={`Remove ${label} filter`}
                onClick={onClear}
                className="grid size-4 place-items-center rounded-full text-muted-foreground hover:bg-foreground/10 hover:text-foreground"
            >
                <X className="size-3" />
            </button>
        </span>
    );
}

export default function PostsIndex({ posts, filters, sets, counts }: Props) {
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

    const platformLabel = PLATFORM_OPTIONS.find(
        (o) => o.value === filters.platform,
    )?.label;
    const setLabel = sets.find((s) => s.id === filters.set)?.name;

    const items = posts?.data ?? [];

    return (
        <>
            <Head title="Posts" />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-0">
                {/* Command bar */}
                <div className="sticky top-0 z-10 flex flex-wrap items-center gap-2 border-b border-border bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                    <h2 className="mr-2 text-[15px] font-semibold tracking-tight">
                        Posts
                    </h2>

                    {/* Search */}
                    <div className="relative order-last w-full sm:order-none sm:max-w-xs sm:flex-1">
                        <Search className="pointer-events-none absolute inset-y-0 left-2.5 my-auto size-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search posts…"
                            value={localQ}
                            onChange={(e) => handleQChange(e.target.value)}
                            className="h-8 pr-7 pl-8 text-sm"
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
                        <DropdownMenuTrigger
                            render={
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-8 gap-1.5"
                                />
                            }
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
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                                Platform
                            </DropdownMenuLabel>
                            {PLATFORM_OPTIONS.map((opt) => (
                                <DropdownMenuCheckboxItem
                                    key={opt.value}
                                    checked={filters.platform === opt.value}
                                    closeOnClick={false}
                                    onCheckedChange={() =>
                                        handlePlatformToggle(opt.value)
                                    }
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
                        <Button
                            nativeButton={false}
                            size="sm"
                            className="h-8"
                            render={<Link href={dashboard().url} />}
                        >
                            New post
                        </Button>
                    </div>
                </div>

                {/* Status tabs */}
                <div className="border-b border-border px-4 py-2">
                    <FilterTabs
                        tabs={STATUS_TABS.map((tab) => ({
                            ...tab,
                            count: counts[tab.value],
                        }))}
                        value={filters.status}
                        onChange={(v) => handleStatusChange(v as StatusTab)}
                    />
                </div>

                {/* Active filter chips */}
                {(filters.platform || filters.set) && (
                    <div className="flex flex-wrap items-center gap-1.5 border-b border-border px-4 py-2">
                        <span className="text-[12px] text-muted-foreground">
                            Filters
                        </span>
                        {filters.platform && (
                            <FilterChip
                                label={platformLabel ?? filters.platform}
                                onClear={() =>
                                    handlePlatformToggle(filters.platform)
                                }
                            />
                        )}
                        {filters.set && (
                            <FilterChip
                                label={setLabel ?? 'Set'}
                                onClear={() => handleSetChange('all')}
                            />
                        )}
                        <button
                            type="button"
                            onClick={() =>
                                applyFilters({ platform: '', set: '' })
                            }
                            className="ml-1 text-[12px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        >
                            Clear all
                        </button>
                    </div>
                )}

                {/* List body */}
                <div className="p-4">
                    <Deferred data="posts" fallback={<PostListSkeleton />}>
                        <>
                            {items.length === 0 ? (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            {hasActiveFilter ? (
                                                <SearchX />
                                            ) : (
                                                <Inbox />
                                            )}
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            {hasActiveFilter
                                                ? 'No matching posts'
                                                : 'No posts yet'}
                                        </EmptyTitle>
                                        {hasActiveFilter && (
                                            <EmptyDescription>
                                                No posts match your search or
                                                filters.
                                            </EmptyDescription>
                                        )}
                                    </EmptyHeader>
                                </Empty>
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
                                            <PostRow
                                                key={post.id}
                                                post={post}
                                            />
                                        ))}
                                    </div>
                                </InfiniteScroll>
                            )}

                            {!hasMore && items.length > 0 && (
                                <p className="mt-4 text-center text-xs text-muted-foreground">
                                    All posts loaded.
                                </p>
                            )}
                        </>
                    </Deferred>
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
