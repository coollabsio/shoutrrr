export type Account = {
    id: string;
    platform: string;
    platform_label: string;
    handle: string;
    display_name: string | null;
    avatar_url: string | null;
    status: 'active' | 'needs_attention';
    status_label: string;
    auth_method: string;
    connected_by: string | null;
    token_expires_at: string | null;
    max_text_length: number;
    max_video_duration_seconds: number;
    x_premium: boolean;
    x_subscription_tier: 'free' | 'basic' | 'premium' | 'premium_plus' | null;
    x_subscription_label: string | null;
    x_subscription_checked_at: string | null;
    is_default: boolean;
    disabled: boolean;
    pds_url: string | null;
    auto_repost_enabled: boolean;
};

export type Capability = {
    platform: string;
    label: string;
    supportsOAuth: boolean;
    supportsAppPassword: boolean;
    supportsWebhook: boolean;
    configured: boolean;
    launched: boolean;
    enabled: boolean;
};
