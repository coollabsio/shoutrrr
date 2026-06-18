export type NotificationItem = {
    id: string;
    event: string;
    title: string;
    body: string;
    href: string | null;
    icon: string;
    read: boolean;
    timeLabel: string;
};

export type NotificationsData = {
    items: NotificationItem[];
    unreadCount: number;
    nextCursor: string | null;
};
