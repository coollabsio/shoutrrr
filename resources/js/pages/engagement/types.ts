export type ReplyItem = {
    id: string;
    platform: 'x' | 'bluesky' | 'linkedin';
    remote_reply_id: string;
    author_handle: string;
    author_name: string | null;
    author_avatar_url: string | null;
    text: string;
    remote_created_at: string;
    is_read: boolean;
    is_liked: boolean;
    is_ours: boolean;
    send_status: 'sending' | 'sent' | 'failed' | null;
    status: 'pending' | 'responded' | 'archived';
    post_target_id: string;
    post_id: string | null;
    post_excerpt: string | null;
    account_handle: string | null;
    account_max_text_length: number | null;
};

export type AccountFacet = {
    id: string;
    handle: string | null;
    platform: string;
};

export type PostFacet = {
    id: string;
    excerpt: string;
    count: number;
};

export type EngagementFilters = {
    account: string;
    platform: string;
    target: string;
    post: string;
    unread: boolean;
};
