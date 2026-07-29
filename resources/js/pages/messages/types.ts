export type PlatformName = 'x' | 'bluesky' | 'instagram' | 'facebook';

export type ConversationItem = {
    id: string;
    platform: PlatformName;
    counterpart_handle: string | null;
    counterpart_name: string | null;
    counterpart_avatar_url: string | null;
    last_message_preview: string | null;
    last_message_at: string | null;
    unread_count: number;
    is_archived: boolean;
    can_reply: boolean;
    window_expires_at: string | null;
    account_handle: string | null;
};

/** Stored on the message row, not a live `post_media` reference. */
export type MessageAttachment = {
    kind: string;
    url: string;
    mime: string;
    alt_text: string | null;
};

export type MessageItem = {
    id: string;
    remote_message_id: string;
    direction: 'inbound' | 'outbound';
    text: string | null;
    attachments: MessageAttachment[];
    remote_created_at: string | null;
    is_ours: boolean;
    send_status: 'sending' | 'sent' | 'failed' | null;
};

export type MessagesFilters = { archived: boolean };
