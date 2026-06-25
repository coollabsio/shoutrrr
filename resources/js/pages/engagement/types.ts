export type ReplyItem = {
    id: string;
    platform: 'x' | 'bluesky' | 'linkedin';
    author_handle: string;
    author_name: string | null;
    author_avatar_url: string | null;
    text: string;
    remote_created_at: string;
    is_read: boolean;
    status: 'pending' | 'responded' | 'archived';
    post_target_id: string;
    post_excerpt: string | null;
    account_handle: string | null;
};

export type AccountFacet = {
    id: string;
    handle: string | null;
    platform: string;
};

export type EngagementFilters = {
    account: string;
    platform: string;
    target: string;
    unread: boolean;
};
