import { Deferred, Head, router, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState, type RefObject } from 'react';
import { toast } from 'sonner';

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
import { Archive, Inbox, MessagesSquare, SearchX } from '@/components/ui/icons';
import { Kbd } from '@/components/ui/kbd';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useIsMobile } from '@/hooks/use-mobile';
import { platformLabel } from '@/lib/platforms';
import { cn } from '@/lib/utils';
import {
    archive as archiveRoute,
    index as messagesRoute,
    read as readRoute,
    respond as respondRoute,
    thread as threadRoute,
} from '@/routes/messages';
import type { MediaView } from '@/types/compose';

import { MessageFilters } from './components/message-filters';
import { MessageStream } from './components/message-stream';
import { MessageThread } from './components/message-thread';
import { QuickMessageBox } from './components/quick-message-box';
import {
    actionErrorMessage,
    adjacentIndex,
    atHandle,
    initials,
    messagesShortcut,
    nextAfterArchive,
} from './helpers';
import type { ConversationItem, MessageItem, MessagesFilters } from './types';

type PageProps = {
    conversations?: { data: ConversationItem[] };
    filters: MessagesFilters;
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
                    {filtered
                        ? 'No conversations match'
                        : 'No conversations yet'}
                </EmptyTitle>
                <EmptyDescription>
                    {filtered
                        ? 'Try clearing a filter to see more of your inbox.'
                        : 'Direct messages to your connected accounts land here after periodic checks.'}
                </EmptyDescription>
            </EmptyHeader>
        </Empty>
    );
}

function ShortcutHint({ keys, label }: { keys: string[]; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-0.5">
                {keys.map((key) => (
                    <Kbd key={key}>{key}</Kbd>
                ))}
            </span>
            <span>{label}</span>
        </span>
    );
}

function ConversationPrompt() {
    return (
        <Empty className="h-full">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <MessagesSquare />
                </EmptyMedia>
                <EmptyTitle>Pick a conversation</EmptyTitle>
                <EmptyDescription>
                    Select a conversation on the left to see the messages and
                    reply.
                </EmptyDescription>
            </EmptyHeader>
            <div className="mt-6 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 px-6">
                <ShortcutHint keys={['↑', '↓']} label="move" />
                <ShortcutHint keys={['A']} label="archive" />
                <ShortcutHint keys={['R']} label="reply" />
            </div>
        </Empty>
    );
}

type ConversationOverride = Partial<
    Pick<
        ConversationItem,
        | 'unread_count'
        | 'last_message_preview'
        | 'last_message_at'
        | 'can_reply'
        | 'window_expires_at'
    >
> & { archived?: boolean };

type RightPaneProps = {
    selected: ConversationItem;
    onArchived: (id: string) => void;
    onResponded: (id: string, preview: string, at: string) => void;
    messageEditorRef?: RefObject<HTMLTextAreaElement | null>;
    reserveCloseButtonSpace?: boolean;
};

/**
 * The conversation pane is a self-owned client island: its actions are plain
 * JSON requests (`useHttp`), never Inertia visits. A visit would follow the
 * response into a fresh `GET /messages`, which drops the deferred
 * `conversations` scroll prop and blanks the left list to a skeleton.
 */
function RightPane({
    selected,
    onArchived,
    onResponded,
    messageEditorRef,
    reserveCloseButtonSpace = false,
}: RightPaneProps) {
    const [thread, setThread] = useState<MessageItem[]>([]);
    const [loading, setLoading] = useState(false);

    const archiveHttp = useHttp<Record<string, never>, null>({});
    const respondHttp = useHttp<
        { text: string; media: string[] },
        { message: MessageItem }
    >({ text: '', media: [] });

    const selectedId = selected.id;

    useEffect(() => {
        setLoading(true);
        fetch(threadRoute(selectedId).url, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((data: { messages: MessageItem[] }) => {
                setThread(data.messages);
            })
            .catch(() => {
                setThread([]);
            })
            .finally(() => setLoading(false));
    }, [selectedId]);

    function setSendStatus(id: string, status: MessageItem['send_status']) {
        setThread((prev) =>
            prev.map((m) => (m.id === id ? { ...m, send_status: status } : m)),
        );
    }

    async function send(text: string, media: MediaView[]) {
        const tempId = `temp-${Date.now()}`;
        const now = new Date().toISOString();
        setThread((prev) => [
            ...prev,
            {
                id: tempId,
                remote_message_id: tempId,
                direction: 'outbound',
                text,
                // Shaped like the stored record, so the real message swaps in
                // without the picture changing.
                attachments: media.map((m) => ({
                    kind: m.kind,
                    url: m.url,
                    mime: m.mime,
                    alt_text: m.alt_text,
                })),
                remote_created_at: now,
                is_ours: true,
                send_status: 'sending' as const,
            },
        ]);
        respondHttp.transform(() => ({
            text,
            media: media.map((m) => m.id),
        }));
        // Failures rethrow so QuickMessageBox's `await onSend(...)` still sees
        // them, but the box already reflects the sending/failed state above.
        await respondHttp.post(respondRoute(selected.id).url, {
            onSuccess: ({ message }) => {
                setThread((prev) =>
                    prev.map((m) => (m.id === tempId ? message : m)),
                );
                // A media-only message has no text to preview.
                onResponded(
                    selected.id,
                    text.trim() !== ''
                        ? text
                        : media[0]?.kind === 'video'
                          ? 'Video'
                          : 'Photo',
                    now,
                );
            },
            onError: () => setSendStatus(tempId, 'failed'),
            onHttpException: (r) => {
                setSendStatus(tempId, 'failed');
                toast.error(
                    actionErrorMessage(r, 'Could not send the message.'),
                );
            },
            onNetworkError: () => {
                setSendStatus(tempId, 'failed');
                toast.error('Could not reach the server.');
            },
        });
    }

    function archive() {
        void archiveHttp
            .post(archiveRoute(selected.id).url, {
                onSuccess: () => onArchived(selected.id),
                onHttpException: (r) => {
                    toast.error(
                        actionErrorMessage(
                            r,
                            'Could not archive the conversation.',
                        ),
                    );
                },
                onNetworkError: () => {
                    toast.error('Could not reach the server.');
                },
            })
            .catch(() => {});
    }

    return (
        <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
            <header
                className={cn(
                    'flex shrink-0 items-center gap-2.5 border-b px-4 py-3',
                    reserveCloseButtonSpace && 'pr-14',
                )}
            >
                <div className="relative shrink-0">
                    <Avatar className="size-8">
                        {selected.counterpart_avatar_url ? (
                            <AvatarImage
                                src={selected.counterpart_avatar_url}
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
                        {selected.counterpart_name ??
                            atHandle(selected.counterpart_handle)}
                    </div>
                    <div className="truncate text-xs text-muted-foreground">
                        {platformLabel(selected.platform)}
                        {selected.account_handle
                            ? ` · via ${selected.account_handle}`
                            : ''}
                    </div>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label="Archive conversation"
                    className="gap-1.5 text-muted-foreground hover:text-foreground"
                    onClick={archive}
                >
                    <Archive className="size-4" />
                    <Kbd className="h-5 min-w-5 px-1 text-[10px]">A</Kbd>
                </Button>
            </header>

            <MessageThread
                conversation={selected}
                messages={thread}
                loading={loading}
            />

            {/* Key by conversation so switching conversations gives a fresh
                draft: remounting clears the local text and any attached media
                instead of carrying the previous conversation's draft over. */}
            <QuickMessageBox
                key={selected.id}
                conversationId={selected.id}
                platform={selected.platform}
                canReply={selected.can_reply}
                replyingTo={atHandle(selected.counterpart_handle)}
                editorRef={messageEditorRef}
                onSend={send}
            />
        </div>
    );
}

export default function MessagesIndex({ conversations, filters }: PageProps) {
    const isMobile = useIsMobile();
    const [selected, setSelected] = useState<ConversationItem | null>(null);
    const messageEditorRef = useRef<HTMLTextAreaElement>(null);
    // Client-side overlay over the deferred `conversations` scroll prop:
    // archiving, marking read, or responding must update the left list without
    // a visit that would refetch (and blank) it. Inertia still owns
    // `conversations` itself — we only derive.
    const [overrides, setOverrides] = useState<
        Record<string, ConversationOverride>
    >({});
    // The keyboard `A` shortcut archives without an Inertia visit, mirroring
    // the conversation pane's plain-JSON action so the deferred list never
    // blanks.
    const archiveHttp = useHttp<Record<string, never>, null>({});
    // Selecting an unread conversation marks it read as a plain-JSON action
    // too, for the same reason — never inside RightPane's thread-fetch effect,
    // since that would pull `selected` into the effect's dependencies and
    // refetch the thread on every unrelated re-render.
    const readHttp = useHttp<Record<string, never>, null>({});

    // Filter changes refetch conversations with reset:['conversations']; stale
    // overrides would wrongly hide rows in, say, the archived view. Reset
    // during render (not an effect) so no wasted commit fires with the old
    // overrides applied.
    const filterKey = `${filters.archived}`;
    const prevFilterKey = useRef(filterKey);
    if (prevFilterKey.current !== filterKey) {
        prevFilterKey.current = filterKey;
        setOverrides({});
    }

    const items = (conversations?.data ?? [])
        .filter((c) => !overrides[c.id]?.archived)
        .map((c) => {
            const override = overrides[c.id];
            return override ? { ...c, ...override } : c;
        });
    const filtered = filters.archived;

    function markRead(id: string) {
        void readHttp
            .post(readRoute(id).url, { onSuccess: () => handleRead(id) })
            .catch(() => {});
    }

    function selectConversation(next: ConversationItem | null) {
        setSelected(next);
        if (next && next.unread_count > 0) {
            markRead(next.id);
        }
    }

    function clearSelection() {
        setSelected(null);
    }

    function selectById(id: string | null) {
        if (id === null) {
            setSelected(null);
            return;
        }

        const next = items.find((item) => item.id === id) ?? null;
        selectConversation(next);
    }

    function moveSelection(delta: 1 | -1) {
        if (items.length === 0) {
            return;
        }

        const currentIndex = selected
            ? items.findIndex((item) => item.id === selected.id)
            : -1;
        const nextIndex = adjacentIndex(items.length, currentIndex, delta);
        const next = items[nextIndex];

        if (!next) {
            return;
        }

        selectConversation(next);
        requestAnimationFrame(() => {
            document
                .getElementById(`message-conversation-${next.id}`)
                ?.scrollIntoView({ block: 'nearest' });
        });
    }

    function archiveSelected() {
        if (!selected) {
            return;
        }

        const archivedId = selected.id;

        void archiveHttp
            .post(archiveRoute(archivedId).url, {
                onSuccess: () => handleArchived(archivedId),
                onHttpException: (r) => {
                    toast.error(
                        actionErrorMessage(
                            r,
                            'Could not archive the conversation.',
                        ),
                    );
                },
                onNetworkError: () => {
                    toast.error('Could not reach the server.');
                },
            })
            .catch(() => {});
    }

    function focusMessageBox() {
        if (!selected) {
            return;
        }

        messageEditorRef.current?.focus();
    }

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            const shortcut = messagesShortcut(event);

            if (!shortcut) {
                return;
            }

            event.preventDefault();

            switch (shortcut.type) {
                case 'next':
                    moveSelection(1);
                    break;
                case 'prev':
                    moveSelection(-1);
                    break;
                case 'archive':
                    archiveSelected();
                    break;
                case 'reply':
                    focusMessageBox();
                    break;
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    });

    function handleArchived(id: string) {
        const nextId = nextAfterArchive(
            items.map((item) => item.id),
            id,
        );
        setOverrides((prev) => ({
            ...prev,
            [id]: { ...prev[id], archived: true },
        }));
        selectById(nextId);
        // The sidebar's unread badge lives on the shared `shell` prop, which the
        // old redirect refreshed incidentally. `conversations` isn't in `only`,
        // so the list keeps its data instead of falling back to the skeleton.
        router.reload({ only: ['shell.unreadMessages'] });
    }

    function handleRead(id: string) {
        setOverrides((prev) => ({
            ...prev,
            [id]: { ...prev[id], unread_count: 0 },
        }));
        router.reload({ only: ['shell.unreadMessages'] });
    }

    function handleResponded(id: string, preview: string, at: string) {
        setOverrides((prev) => ({
            ...prev,
            [id]: {
                ...prev[id],
                last_message_preview: preview,
                last_message_at: at,
            },
        }));
    }

    return (
        <>
            <Head title="Messages" />

            {/*
              Fill the viewport below the sticky app header (h-16) so each
              column owns its own scroll and the message box stays pinned. On
              md+ the sidebar `variant="inset"` gives the <main> an `m-2` (1rem
              of vertical margin), so the desk is `100svh - 4rem - 1rem`;
              without that extra rem the desk overran its inset and spilled a
              window scrollbar under the shortcut bar.
            */}
            <div className="grid h-[calc(100svh-4rem)] min-h-0 grid-cols-1 overflow-hidden md:h-[calc(100svh-5rem)] md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                {/* Left: conversation list */}
                <div className="flex min-h-0 min-w-0 flex-col overflow-hidden border-r">
                    <div className="shrink-0">
                        <MessageFilters filters={filters} />
                    </div>
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <Deferred
                            data="conversations"
                            fallback={<StreamSkeleton />}
                        >
                            {items.length === 0 ? (
                                <StreamEmpty filtered={filtered} />
                            ) : (
                                <MessageStream
                                    conversations={items}
                                    selectedId={selected?.id ?? null}
                                    onSelect={selectConversation}
                                />
                            )}
                        </Deferred>
                    </div>
                    {items.length > 0 ? (
                        <div className="hidden shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-t px-3 py-2 md:flex">
                            <ShortcutHint keys={['↑', '↓']} label="move" />
                            <ShortcutHint keys={['A']} label="archive" />
                            <ShortcutHint keys={['R']} label="reply" />
                        </div>
                    ) : null}
                </div>

                {/* Right: conversation desk (desktop) */}
                {!isMobile ? (
                    <div className="hidden min-h-0 min-w-0 flex-col overflow-hidden md:flex">
                        {selected ? (
                            <RightPane
                                selected={selected}
                                onArchived={handleArchived}
                                onResponded={handleResponded}
                                messageEditorRef={messageEditorRef}
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
                        className="flex h-full w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-md"
                    >
                        <SheetTitle className="sr-only">
                            Conversation
                        </SheetTitle>
                        {selected ? (
                            <RightPane
                                selected={selected}
                                onArchived={handleArchived}
                                onResponded={handleResponded}
                                messageEditorRef={messageEditorRef}
                                reserveCloseButtonSpace
                            />
                        ) : null}
                    </SheetContent>
                </Sheet>
            ) : null}
        </>
    );
}

MessagesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Messages',
            href: messagesRoute().url,
        },
    ],
};
