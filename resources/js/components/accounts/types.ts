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
    x_premium: boolean;
    is_default: boolean;
    pds_url: string | null;
};

export type Capability = {
    platform: string;
    label: string;
    supportsOAuth: boolean;
    supportsAppPassword: boolean;
    configured: boolean;
};
