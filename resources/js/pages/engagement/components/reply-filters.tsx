import { router } from '@inertiajs/react';
import { Check, ChevronDown, FileText, X } from 'lucide-react';
import { useState } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { index as engagementRoute } from '@/routes/engagement';
import type { PlatformName } from '@/types/compose';

import type { AccountFacet, EngagementFilters, PostFacet } from '../types';

type Props = {
    filters: EngagementFilters;
    accounts: AccountFacet[];
    posts: PostFacet[];
};

const PLATFORMS: { value: PlatformName; label: string }[] = [
    { value: 'bluesky', label: 'Bluesky' },
    { value: 'x', label: 'X' },
    { value: 'linkedin', label: 'LinkedIn' },
];

export function ReplyFilters({ filters, accounts, posts }: Props) {
    const [postPickerOpen, setPostPickerOpen] = useState(false);
    const activePost = posts.find((p) => p.id === filters.post);

    function update(patch: Partial<EngagementFilters>) {
        const next = { ...filters, ...patch };
        router.get(
            engagementRoute().url,
            next as Record<string, string | boolean>,
            {
                preserveState: true,
                preserveScroll: true,
                only: ['replies', 'filters'],
                reset: ['replies'],
                replace: true,
            },
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2 border-b px-3 py-2.5">
            <ToggleGroup
                value={[
                    filters.archived
                        ? 'archived'
                        : filters.unread
                          ? 'unread'
                          : 'all',
                ]}
                onValueChange={(value) => {
                    const v = value[0];
                    if (v) {
                        update({
                            unread: v === 'unread',
                            archived: v === 'archived',
                        });
                    }
                }}
                variant="outline"
                size="sm"
            >
                <ToggleGroupItem value="all" className="px-3 text-xs">
                    All
                </ToggleGroupItem>
                <ToggleGroupItem value="unread" className="px-3 text-xs">
                    Unread
                </ToggleGroupItem>
                <ToggleGroupItem value="archived" className="px-3 text-xs">
                    Archived
                </ToggleGroupItem>
            </ToggleGroup>

            <Select
                value={filters.platform || 'all'}
                onValueChange={(v) =>
                    update({ platform: !v || v === 'all' ? '' : v })
                }
            >
                <SelectTrigger size="sm" className="h-8 w-auto gap-1.5 text-xs">
                    <SelectValue placeholder="Platform" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All platforms</SelectItem>
                    {PLATFORMS.map((p) => (
                        <SelectItem key={p.value} value={p.value}>
                            <PlatformGlyph platform={p.value} />
                            {p.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {accounts.length > 0 ? (
                <Select
                    value={filters.account || 'all'}
                    onValueChange={(v) =>
                        update({ account: !v || v === 'all' ? '' : v })
                    }
                >
                    <SelectTrigger
                        size="sm"
                        className="h-8 w-auto gap-1.5 text-xs"
                    >
                        <SelectValue placeholder="Account" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All accounts</SelectItem>
                        {accounts.map((a) => (
                            <SelectItem key={a.id} value={a.id}>
                                {a.handle ?? a.id}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            ) : null}

            {activePost ? (
                <Badge
                    variant="secondary"
                    className="h-8 gap-1.5 rounded-md pr-1 pl-2.5 font-normal"
                >
                    <FileText className="size-3 shrink-0 text-muted-foreground" />
                    <span className="max-w-40 truncate">
                        {activePost.excerpt}
                    </span>
                    <button
                        type="button"
                        aria-label="Clear post filter"
                        onClick={() => update({ post: '' })}
                        className="rounded-sm p-0.5 text-muted-foreground hover:bg-background hover:text-foreground"
                    >
                        <X className="size-3.5" />
                    </button>
                </Badge>
            ) : posts.length > 0 ? (
                <Popover open={postPickerOpen} onOpenChange={setPostPickerOpen}>
                    <PopoverTrigger
                        render={
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8 gap-1.5 text-xs text-muted-foreground"
                            />
                        }
                    >
                        <FileText className="size-3.5" />
                        Filter by post
                        <ChevronDown className="size-3.5 opacity-60" />
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-80 p-0">
                        <Command>
                            <CommandInput placeholder="Search your posts…" />
                            <CommandList>
                                <CommandEmpty>
                                    No posts with replies.
                                </CommandEmpty>
                                <CommandGroup>
                                    {posts.map((p) => (
                                        <CommandItem
                                            key={p.id}
                                            value={`${p.excerpt} ${p.id}`}
                                            onSelect={() => {
                                                update({ post: p.id });
                                                setPostPickerOpen(false);
                                            }}
                                            className="gap-2"
                                        >
                                            <Check
                                                className={cn(
                                                    'size-3.5 shrink-0',
                                                    p.id === filters.post
                                                        ? 'opacity-100'
                                                        : 'opacity-0',
                                                )}
                                            />
                                            <span className="min-w-0 flex-1 truncate">
                                                {p.excerpt}
                                            </span>
                                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                {p.count}
                                            </span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            </CommandList>
                        </Command>
                    </PopoverContent>
                </Popover>
            ) : null}
        </div>
    );
}
