import type { EditSettings } from '@/lib/image-editor/settings';

export const BASE_TAB = '__base__';

export type PlatformName =
    | 'x'
    | 'bluesky'
    | 'linkedin'
    | 'facebook'
    | 'instagram'
    | 'threads'
    | 'discord';

export type PostFormat = 'feed' | 'reels' | 'story';

/**
 * Per-platform display text / handles for a mention, plus the non-platform
 * `linkedin_urn` key which carries a raw LinkedIn company URL / numeric id /
 * `urn:li:organization:ID`. The server normalizes it into a canonical URN on
 * save; the client only captures and round-trips the raw string.
 */
export type MentionHandles = Partial<
    Record<PlatformName | 'linkedin_urn', string>
>;

export type WorkspaceMention = {
    id: string;
    name: string;
    handles: MentionHandles;
};

export type MentionPlaceholder = {
    id: string;
    label: string;
    handles: MentionHandles;
};

export type Destination =
    | { kind: 'all' }
    | { kind: 'set'; id: string }
    | { kind: 'account'; id: string }
    | { kind: 'accounts'; ids: string[] };

export type AccountStatus = 'active' | 'needs_attention';

export type Account = {
    id: string;
    platform: PlatformName;
    handle: string;
    display_name: string | null;
    avatar_url: string | null;
    status?: AccountStatus;
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
    /** Platform rejects a post with no image or video (Instagram). */
    requiresMedia: boolean;
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
    /** Image vs video — drives whether the preview chip renders <img> or <video>. */
    kind: 'image' | 'video';
    /** Local object-URL preview shown immediately; absent where unsupported. */
    previewUrl?: string;
    status: 'processing' | 'uploading' | 'error';
    /** Progress 0–100; set during client-side compression and the storage PUT. */
    progress?: number;
};

export type TargetStatus =
    | 'pending'
    | 'publishing'
    | 'published'
    | 'failed'
    | 'skipped'
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
    content_override: { segments?: string[]; media_ids?: string[] } | null;
    auto_split: boolean;
    format: PostFormat;
    issues: string[];
    status: TargetStatus;
    error_kind: string | null;
    error_message: string | null;
    attempts: number;
    remote_id: string | null;
};

export type PostView = {
    id: string;
    base_text: string;
    segments: string[];
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
