import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArrowUpRight,
    Inbox,
    MessagesSquare,
    SearchX,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useIsMobile } from '@/hooks/use-mobile';
import { platformLabel } from '@/lib/posts/permalink';
import {
    archive as archiveRoute,
    destroy as destroyRoute,
    index as engagementRoute,
    like as likeRoute,
    respond as respondRoute,
    thread as threadRoute,
    unlike as unlikeRoute,
} from '@/routes/engagement';

import { QuickReplyBox } from './components/quick-reply-box';
import { ReplyFilters } from './components/reply-filters';
import { ReplyStream } from './components/reply-stream';
import { ReplyThread } from './components/reply-thread';
import { atHandle, initials } from './helpers';
import type {
    AccountFacet,
    EngagementFilters,
    PostFacet,
    ReplyItem,
} from './types';

type PageProps = {
    replies?: { data: ReplyItem[] };
    filters: EngagementFilters;
    facets: { accounts: AccountFacet[]; posts: PostFacet[] };
};

function StreamSkeleton() {
    return (
        <div className="space-y-1 p-3">
            {[0, 1, 2, 3, 4, 5].map((i) => (
                <div key={i} className="flex gap-3 py-2">
                    <Skeleton className="size-9 shrink-0 rounded-full" />
                    <div className="flex-1 space-y-2 py-1">
                        <Skeleton className="h-3 w-1/3" />
                        <Skeleton className="h-3 w-2/3" />
                    </div>
                </div>
            ))}
        </div>
    );
}

function StreamEmpty({ filtered }: { filtered: boolean }) {
    return (
        <Empty className="h-full">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    {filtered ? <SearchX /> : <Inbox />}
                </EmptyMedia>
                <EmptyTitle>
                    {filtered ? 'No replies match' : 'No replies yet'}
                </EmptyTitle>
                <EmptyDescription>
                    {filtered
                        ? 'Try clearing a filter to see more of your inbox.'
                        : 'Replies to your published posts land here after periodic checks. Publish a post and check back later.'}
                </EmptyDescription>
            </EmptyHeader>
        </Empty>
    );
}

function ConversationPrompt() {
    return (
        <Empty className="h-full">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <MessagesSquare />
                </EmptyMedia>
                <EmptyTitle>Pick a reply</EmptyTitle>
                <EmptyDescription>
                    Select a reply on the left to see the conversation and
                    respond.
                </EmptyDescription>
            </EmptyHeader>
        </Empty>
    );
}

type RightPaneProps = {
    selected: ReplyItem;
    onArchived: () => void;
};

function RightPane({ selected, onArchived }: RightPaneProps) {
    const [thread, setThread] = useState<ReplyItem[]>([]);
    const [postExcerpt, setPostExcerpt] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const selectedId = selected.id;

    useEffect(() => {
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

    async function send(text: string, mediaIds: string[]) {
        const tempId = `temp-${Date.now()}`;
        setThread((prev) => [
            ...prev,
            {
                ...selected,
                id: tempId,
                text,
                is_read: true,
                is_ours: true,
                status: 'responded',
                send_status: 'sending' as const,
            },
        ]);
        await new Promise<void>((resolve, reject) => {
            router.post(
                respondRoute(selected.id).url,
                { text, media: mediaIds },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        // Text replies post synchronously, so they're done. Media
                        // replies hand off to an async job — keep them "sending"
                        // until a later thread refetch reflects the real outcome.
                        const stillSending = mediaIds.length > 0;
                        setThread((prev) =>
                            prev.map((r) =>
                                r.id === tempId
                                    ? {
                                          ...r,
                                          send_status: stillSending
                                              ? ('sending' as const)
                                              : null,
                                      }
                                    : r,
                            ),
                        );
                        resolve();
                    },
                    onError: () => {
                        setThread((prev) =>
                            prev.map((r) =>
                                r.id === tempId
                                    ? {
                                          ...r,
                                          send_status: 'failed' as const,
                                      }
                                    : r,
                            ),
                        );
                        reject(new Error('send failed'));
                    },
                },
            );
        });
    }

    function toggleLike(reply: ReplyItem) {
        const wasLiked = reply.is_liked;
        setThread((prev) =>
            prev.map((r) =>
                r.id === reply.id ? { ...r, is_liked: !wasLiked } : r,
            ),
        );
        const restore = () =>
            setThread((prev) =>
                prev.map((r) =>
                    r.id === reply.id ? { ...r, is_liked: wasLiked } : r,
                ),
            );
        if (wasLiked) {
            router.delete(unlikeRoute(reply.id).url, {
                preserveScroll: true,
                onError: restore,
            });
        } else {
            router.post(
                likeRoute(reply.id).url,
                {},
                { preserveScroll: true, onError: restore },
            );
        }
    }

    function remove(reply: ReplyItem) {
        const index = thread.findIndex((r) => r.id === reply.id);
        setThread((prev) => prev.filter((r) => r.id !== reply.id));
        router.delete(destroyRoute(reply.id).url, {
            preserveScroll: true,
            onError: () =>
                setThread((prev) => {
                    const next = [...prev];
                    next.splice(index < 0 ? next.length : index, 0, reply);
                    return next;
                }),
        });
    }

    return (
        <div className="flex h-full flex-col">
            <header className="flex items-center gap-2.5 border-b px-4 py-3">
                <div className="relative shrink-0">
                    <Avatar className="size-8">
                        {selected.author_avatar_url ? (
                            <AvatarImage
                                src={selected.author_avatar_url}
                                alt=""
                            />
                        ) : null}
                        <AvatarFallback className="text-[11px]">
                            {initials(selected)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="absolute -right-0.5 -bottom-0.5 flex size-3.5 items-center justify-center rounded-full bg-background text-muted-foreground ring-1 ring-border">
                        <PlatformGlyph platform={selected.platform} size={8} />
                    </span>
                </div>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold">
                        {selected.author_name ??
                            atHandle(selected.author_handle)}
                    </div>
                    <div className="truncate text-xs text-muted-foreground">
                        {platformLabel(selected.platform)}
                        {selected.account_handle
                            ? ` · to ${selected.account_handle}`
                            : ''}
                    </div>
                </div>
                {selected.post_id ? (
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="gap-1 text-muted-foreground hover:text-foreground"
                    >
                        <Link
                            href={ComposerController.show(selected.post_id).url}
                        >
                            Post
                            <ArrowUpRight className="size-3.5" />
                        </Link>
                    </Button>
                ) : null}
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Archive reply"
                    className="text-muted-foreground hover:text-foreground"
                    onClick={() =>
                        router.post(
                            archiveRoute(selected.id).url,
                            {},
                            { preserveScroll: true, onSuccess: onArchived },
                        )
                    }
                >
                    <Archive className="size-4" />
                </Button>
            </header>

            <ReplyThread
                postExcerpt={postExcerpt}
                postId={selected.post_id}
                thread={thread}
                loading={loading}
                onToggleLike={toggleLike}
                onDelete={remove}
            />

            <QuickReplyBox
                replyId={selected.id}
                platform={selected.platform}
                replyingTo={atHandle(selected.author_handle)}
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
    const isMobile = useIsMobile();
    const [selected, setSelected] = useState<ReplyItem | null>(null);

    const items = replies?.data ?? [];
    const filtered =
        filters.unread ||
        filters.platform !== '' ||
        filters.account !== '' ||
        filters.post !== '' ||
        filters.target !== '';

    function clearSelection() {
        setSelected(null);
    }

    return (
        <>
            <Head title="Engagement" />

            <div className="grid h-full grid-cols-1 md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                {/* Left: triage column */}
                <div className="flex min-h-0 flex-col border-r">
                    <ReplyFilters
                        filters={filters}
                        accounts={facets.accounts}
                        posts={facets.posts}
                    />
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <Deferred data="replies" fallback={<StreamSkeleton />}>
                            {items.length === 0 ? (
                                <StreamEmpty filtered={filtered} />
                            ) : (
                                <ReplyStream
                                    replies={items}
                                    selectedId={selected?.id ?? null}
                                    onSelect={setSelected}
                                />
                            )}
                        </Deferred>
                    </div>
                </div>

                {/* Right: conversation desk (desktop) */}
                {!isMobile ? (
                    <div className="hidden min-h-0 flex-col md:flex">
                        {selected ? (
                            <RightPane
                                selected={selected}
                                onArchived={clearSelection}
                            />
                        ) : (
                            <ConversationPrompt />
                        )}
                    </div>
                ) : null}
            </div>

            {/* Conversation as a slide-over on mobile */}
            {isMobile ? (
                <Sheet
                    open={selected !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            clearSelection();
                        }
                    }}
                >
                    <SheetContent
                        side="right"
                        className="flex w-full flex-col gap-0 p-0 sm:max-w-md"
                    >
                        <SheetTitle className="sr-only">
                            Conversation
                        </SheetTitle>
                        {selected ? (
                            <RightPane
                                selected={selected}
                                onArchived={clearSelection}
                            />
                        ) : null}
                    </SheetContent>
                </Sheet>
            ) : null}
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
