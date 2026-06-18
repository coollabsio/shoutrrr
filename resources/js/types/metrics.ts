import type { PlatformName } from '@/types/compose';

export type MetricsStatus = 'ok' | 'unsupported' | 'rate_limited' | 'failed';

export type PostStatTarget = {
    id: string;
    platform: PlatformName;
    handle: string | null;
    display_name: string | null;
    avatar_url: string | null;
    status: MetricsStatus | null;
    likes: number;
    comments: number;
    reposts: number;
    impressions: number | null;
    captured_at: string | null;
    series: {
        at: string;
        likes: number;
        comments: number;
        reposts: number;
        impressions: number | null;
    }[];
};

export type PostStatsPayload = {
    supported: boolean;
    captured_at: string | null;
    totals: { likes: number; comments: number; reposts: number };
    targets: PostStatTarget[];
};

export type AnalyticsAccount = {
    id: string;
    platform: PlatformName;
    handle: string;
    display_name: string | null;
    avatar_url: string | null;
    status: MetricsStatus | null;
    latest_followers: number | null;
    series: { at: string; followers: number; following: number | null }[];
};

export type AnalyticsPostMarker = {
    id: string;
    title: string;
    published_at: string;
    platforms: PlatformName[];
};

export type AnalyticsComparisonRow = AnalyticsPostMarker & {
    engagement: number;
};

export type AnalyticsPageProps = {
    accounts: AnalyticsAccount[];
    posts: AnalyticsPostMarker[];
    comparison: {
        top: AnalyticsComparisonRow[];
        bottom: AnalyticsComparisonRow[];
    };
    rangeDays: number;
};
