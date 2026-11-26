import type { PlatformName } from '@/types/compose';

const PLATFORM_LABELS: Record<string, string> = {
    x: 'X',
    bluesky: 'Bluesky',
    linkedin: 'LinkedIn',
    facebook: 'Facebook',
    instagram: 'Instagram',
    threads: 'Threads',
    discord: 'Discord',
    tiktok: 'TikTok',
};

export function platformLabel(platform: string): string {
    return PLATFORM_LABELS[platform] ?? platform;
}

export function platformKeys(
    enabled: Record<PlatformName, boolean>,
): PlatformName[] {
    return Object.keys(enabled) as PlatformName[];
}

export function disabledPlatformLabels(
    enabled: Record<PlatformName, boolean>,
): string[] {
    return platformKeys(enabled)
        .filter((platform) => !enabled[platform])
        .map((platform) => platformLabel(platform));
}
