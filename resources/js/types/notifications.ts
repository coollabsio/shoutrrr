export type NotificationAction = {
    key: string;
    label: string;
    variant: 'primary' | 'secondary' | 'destructive';
    method: 'post' | 'delete';
    href: string;
};

export type NotificationItem = {
    id: string;
    event: string;
    title: string;
    body: string;
    href: string | null;
    icon: string;
    read: boolean;
    timeLabel: string;
    actions?: NotificationAction[];
};

export type NotificationsData = {
    items: NotificationItem[];
    unreadCount: number;
    nextCursor: string | null;
};
