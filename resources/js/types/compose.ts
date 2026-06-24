import type { EditSettings } from '@/lib/image-editor/settings';

export const BASE_TAB = '__base__';

export type PlatformName = 'x' | 'bluesky' | 'linkedin';

export type WorkspaceMention = {
    id: string;
    name: string;
    handles: Partial<Record<PlatformName, string>>;
};

export type MentionPlaceholder = {
    id: string;
    label: string;
    handles: Partial<Record<PlatformName, string>>;
};

export type Destination =
    | { kind: 'all' }
    | { kind: 'set'; id: string }
    | { kind: 'account'; id: string }
    | { kind: 'accounts'; ids: string[] };

export type Account = {
    id: string;
    platform: PlatformName;
    handle: string;
    display_name: string | null;
    avatar_url: string | null;
    max_text_length: number;
    x_premium: boolean;
};

export type AccountSet = {
    id: string;
    name: string;
    connected_account_ids: string[];
};

export type PlatformLimits = {
    platform: PlatformName;
    maxLength: number;
    maxBytes: number | null;
    maxMedia: number;
    maxMediaBytes: number;
    allowedMime: string[];
    threadMax: number | null;
    maxImageDimensions: { width: number; height: number };
    allowedVideoMime: string[];
    maxVideoBytes: number;
    maxVideoDurationSeconds: number;
};

export type MediaView = {
    id: string;
    url: string;
    mime: string;
    kind: 'image' | 'video';
    alt_text: string | null;
    duration_seconds: number | null;
    position: number;
    edit_settings: EditSettings | null;
    source_url: string | null;
};

/** An upload still in flight (or just failed) — rendered as a ghost chip. */
export type PendingUpload = {
    tempId: string;
    /** Local object-URL preview shown immediately; absent where unsupported. */
    previewUrl?: string;
    status: 'uploading' | 'error';
    /** Upload progress 0–100; only set during direct-to-storage PUT. */
    progress?: number;
};

export type TargetStatus =
    | 'pending'
    | 'publishing'
    | 'published'
    | 'failed'
    | 'deleting'
    | 'deleted';

export type PostStatus =
    | 'draft'
    | 'scheduled'
    | 'publishing'
    | 'published'
    | 'partial'
    | 'failed'
    | 'missed'
    | 'deleted';

export type TargetView = {
    id: string;
    connected_account_id: string;
    platform: PlatformName;
    handle: string | null;
    display_name: string | null;
    avatar_url: string | null;
    sections: string[];
    content_override: { text?: string | null; media_ids?: string[] } | null;
    auto_split: boolean;
    issues: string[];
    status: TargetStatus;
    error_kind: string | null;
    error_message: string | null;
    remote_id: string | null;
};

export type PostView = {
    id: string;
    base_text: string;
    mentions?: MentionPlaceholder[];
    status: PostStatus;
    published_at: string | null;
    updated_at: string;
    scheduled_at: string | null;
    destination: { kind: string; id: string | null; ids?: string[] };
    targets: TargetView[];
    media: MediaView[];
};

export type ComposePageProps = {
    post: PostView | null;
    accounts: Account[];
    sets: AccountSet[];
    limits: PlatformLimits[];
    savedMentions: WorkspaceMention[];
};
