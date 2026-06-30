import { Link, router, useHttp, usePage } from '@inertiajs/react';
import { Bell, Loader2, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import {
    index as notificationsIndex,
    destroy as deleteNotification,
    destroyAll as deleteAllNotifications,
    read as markRead,
    readAll as markAllRead,
} from '@/routes/notifications';
import type {
    NotificationAction,
    NotificationItem,
    NotificationsData,
} from '@/types/notifications';

/** Load the next page once the user scrolls within this many px of the bottom. */
const SCROLL_THRESHOLD = 64;

const readNotificationIds = new Set<string>();
const deletedNotificationIds = new Set<string>();

function applyLocalNotificationState(
    data: NotificationsData,
): NotificationsData {
    const items = data.items
        .filter((notification) => !deletedNotificationIds.has(notification.id))
        .map((notification) =>
            readNotificationIds.has(notification.id)
                ? { ...notification, read: true }
                : notification,
        );
    const locallyClearedUnreadCount = data.items.filter(
        (notification) =>
            !notification.read &&
            (readNotificationIds.has(notification.id) ||
                deletedNotificationIds.has(notification.id)),
    ).length;

    return {
        ...data,
        items,
        unreadCount: Math.max(0, data.unreadCount - locallyClearedUnreadCount),
    };
}

export function NotificationBell() {
    const { notifications } = usePage().props;
    const initialNotifications = applyLocalNotificationState(notifications);
    const [items, setItems] = useState<NotificationItem[]>(
        initialNotifications.items,
    );
    const [cursor, setCursor] = useState<string | null>(
        initialNotifications.nextCursor,
    );
    // Authoritative total from the server — `items` only holds loaded pages, so
    // deriving the count from it would undercount once more pages exist.
    const [unread, setUnread] = useState(initialNotifications.unreadCount);
    const { get, processing } = useHttp<
        Record<string, never>,
        NotificationsData
    >({});
    const { post, delete: destroy } = useHttp<Record<string, never>, null>({});
    // Synchronous guard: `processing` state lags behind rapid scroll events, so
    // a ref prevents firing duplicate requests for the same cursor.
    const loadingRef = useRef(false);

    // The shared prop refreshes on navigation and polling; re-seed the list,
    // cursor, and count from the freshest first page when it changes.
    useEffect(() => {
        const locallySyncedNotifications =
            applyLocalNotificationState(notifications);

        setItems(locallySyncedNotifications.items);
        setCursor(locallySyncedNotifications.nextCursor);
        setUnread(locallySyncedNotifications.unreadCount);
    }, [notifications]);

    function loadMore() {
        if (cursor === null || loadingRef.current) {
            return;
        }

        loadingRef.current = true;
        void get(notificationsIndex({ query: { cursor } }).url, {
            onSuccess: (data) => {
                const locallySyncedData = applyLocalNotificationState(data);

                setItems((prev) => {
                    const seen = new Set(prev.map((n) => n.id));
                    return [
                        ...prev,
                        ...locallySyncedData.items.filter(
                            (n) => !seen.has(n.id),
                        ),
                    ];
                });
                setCursor(locallySyncedData.nextCursor);
            },
            onFinish: () => {
                loadingRef.current = false;
            },
        });
    }

    function handleScroll(event: React.UIEvent<HTMLDivElement>) {
        const el = event.currentTarget;
        if (
            el.scrollHeight - el.scrollTop - el.clientHeight <=
            SCROLL_THRESHOLD
        ) {
            loadMore();
        }
    }

    function markOneRead(id: string) {
        const wasUnread = items.some((n) => n.id === id && !n.read);
        readNotificationIds.add(id);

        if (wasUnread) {
            setUnread((count) => Math.max(0, count - 1));
        }
        setItems((prev) =>
            prev.map((n) => (n.id === id ? { ...n, read: true } : n)),
        );
        void post(markRead(id).url);
    }

    function markEverythingRead() {
        items.forEach((notification) => {
            readNotificationIds.add(notification.id);
        });

        setItems((prev) => prev.map((n) => ({ ...n, read: true })));
        setUnread(0);
        void post(markAllRead().url);
    }

    function deleteOne(notification: NotificationItem) {
        deletedNotificationIds.add(notification.id);

        if (!notification.read) {
            setUnread((count) => Math.max(0, count - 1));
        }

        setItems((prev) => prev.filter((n) => n.id !== notification.id));

        void destroy(deleteNotification(notification.id).url);
    }

    function deleteEverything() {
        items.forEach((notification) => {
            deletedNotificationIds.add(notification.id);
        });

        setItems([]);
        setUnread(0);

        void destroy(deleteAllNotifications().url);
    }

    function handleAction(
        notification: NotificationItem,
        action: NotificationAction,
    ) {
        if (!notification.read) {
            setUnread((count) => Math.max(0, count - 1));
        }

        setItems((prev) => prev.filter((n) => n.id !== notification.id));

        router.visit(action.href, {
            method: action.method,
            preserveScroll: true,
            preserveState: action.method === 'delete',
        });
    }

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-8 text-muted-foreground"
                    aria-label={
                        unread > 0
                            ? `Notifications (${unread} unread)`
                            : 'Notifications'
                    }
                >
                    <Bell className="size-4" />
                    {unread > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-medium text-white tabular-nums">
                            {unread > 9 ? '9+' : unread}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 gap-0 p-0">
                <div className="flex items-center justify-between border-b border-border px-3 py-2">
                    <span className="text-[13px] font-semibold">
                        Notifications
                    </span>
                    <div className="flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-6 px-2 text-[12px]"
                            disabled={unread === 0}
                            onClick={markEverythingRead}
                        >
                            Mark all read
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            className="text-muted-foreground hover:text-destructive"
                            disabled={items.length === 0}
                            aria-label="Delete all notifications"
                            onClick={deleteEverything}
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    </div>
                </div>

                <div
                    className="max-h-96 overflow-y-auto"
                    onScroll={handleScroll}
                >
                    {items.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-3 py-10 text-center">
                            <span className="grid size-9 place-items-center rounded-full bg-muted text-muted-foreground">
                                <Bell className="size-4" />
                            </span>
                            <p className="text-[12.5px] text-muted-foreground">
                                {"You're all caught up"}
                            </p>
                        </div>
                    ) : (
                        <>
                            {items.map((notification) => (
                                <NotificationRow
                                    key={notification.id}
                                    notification={notification}
                                    onRead={markOneRead}
                                    onDelete={deleteOne}
                                    onAction={handleAction}
                                />
                            ))}
                            {processing && (
                                <div className="flex justify-center py-3">
                                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                                </div>
                            )}
                        </>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function NotificationRow({
    notification,
    onRead,
    onDelete,
    onAction,
}: {
    notification: NotificationItem;
    onRead: (id: string) => void;
    onDelete: (notification: NotificationItem) => void;
    onAction: (
        notification: NotificationItem,
        action: NotificationAction,
    ) => void;
}) {
    const hasActions =
        notification.actions !== undefined && notification.actions.length > 0;

    const content = (
        <div className="min-w-0 flex-1 py-2.5 text-left">
            <div className="flex items-center gap-2.5">
                <span
                    aria-hidden
                    className={cn(
                        'size-1.5 shrink-0 rounded-full',
                        notification.read ? 'bg-transparent' : 'bg-primary',
                    )}
                />
                <p className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
                    {notification.title}
                </p>
            </div>
            <div className="pl-4">
                {notification.body && (
                    <p className="mt-0.5 line-clamp-2 text-[12px] text-muted-foreground">
                        {notification.body}
                    </p>
                )}
                <p className="mt-1 text-[11px] text-muted-foreground/70">
                    {notification.timeLabel}
                </p>
                {hasActions && (
                    <div className="mt-2 flex flex-wrap gap-2">
                        {notification.actions?.map((action) => (
                            <Button
                                key={action.key}
                                type="button"
                                size="xs"
                                variant={buttonVariant(action.variant)}
                                onClick={(event) => {
                                    event.stopPropagation();
                                    onAction(notification, action);
                                }}
                            >
                                {action.label}
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );

    const deleteButton = (
        <Button
            type="button"
            variant="ghost"
            size="icon-xs"
            className="mt-2.5 mr-2 shrink-0 text-muted-foreground hover:text-destructive"
            aria-label="Delete notification"
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onDelete(notification);
            }}
        >
            <Trash2 className="size-3.5" />
        </Button>
    );

    if (notification.href && !hasActions) {
        return (
            <div className="flex border-b border-border transition-colors last:border-b-0 hover:bg-muted/50">
                <Link
                    href={notification.href}
                    className="min-w-0 flex-1 pl-3"
                    onClick={() => onRead(notification.id)}
                >
                    {content}
                </Link>
                {deleteButton}
            </div>
        );
    }

    return (
        <div
            role="button"
            tabIndex={0}
            className="flex border-b border-border transition-colors last:border-b-0 hover:bg-muted/50"
            onClick={() => onRead(notification.id)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (e.key === ' ') {
                        e.preventDefault();
                    }
                    onRead(notification.id);
                }
            }}
        >
            <div className="min-w-0 flex-1 pl-3">{content}</div>
            {deleteButton}
        </div>
    );
}

function buttonVariant(
    variant: NotificationAction['variant'],
): React.ComponentProps<typeof Button>['variant'] {
    if (variant === 'primary') {
        return 'default';
    }

    return variant;
}
