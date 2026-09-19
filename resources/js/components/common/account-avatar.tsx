import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

/** Per-platform brand accent for the glyph badge (mirrors the accounts page). */
export const PLATFORM_BRAND: Record<string, { tile: string; glyph: string }> = {
    x: { tile: 'bg-white', glyph: 'text-black!' },
    linkedin: { tile: 'bg-blue-600', glyph: 'text-white!' },
    bluesky: { tile: 'bg-sky-500', glyph: 'text-white!' },
    facebook: { tile: 'bg-[#1877F2]', glyph: 'text-white!' },
    instagram: { tile: 'bg-[#E4405F]', glyph: 'text-white!' },
    threads: { tile: 'bg-black', glyph: 'text-white!' },
    discord: { tile: 'bg-[#5865F2]', glyph: 'text-white!' },
};

const PLATFORM_FALLBACK = { tile: 'bg-muted', glyph: 'text-muted-foreground' };

const SIZES = {
    sm: {
        avatar: 'size-5',
        fallback: 'text-[9px]',
        badge: 'size-2.5',
        glyph: 'size-1.5',
    },
    md: {
        avatar: 'size-8',
        fallback: 'text-[11px]',
        badge: 'size-4',
        glyph: 'size-2.5',
    },
} as const;

type Props = {
    platform: string;
    handle: string;
    avatarUrl?: string | null;
    size?: keyof typeof SIZES;
    /** Ring color of the badge. Match the surface it sits on (card, popover…). */
    ringClassName?: string;
};

/**
 * Account avatar with the platform logo tucked into the bottom-right corner —
 * the standard way a connected account is shown across the app.
 */
export function AccountAvatar({
    platform,
    handle,
    avatarUrl,
    size = 'sm',
    ringClassName = 'ring-background',
}: Props) {
    const brand = PLATFORM_BRAND[platform] ?? PLATFORM_FALLBACK;
    const s = SIZES[size];

    return (
        <span className="relative inline-grid shrink-0">
            <Avatar className={s.avatar}>
                <AvatarImage src={avatarUrl ?? undefined} alt={handle} />
                <AvatarFallback className={cn(s.fallback, 'font-medium')}>
                    {handle.replace(/^@/, '').slice(0, 1).toUpperCase()}
                </AvatarFallback>
            </Avatar>
            <span
                className={cn(
                    'absolute right-0 bottom-0 grid place-items-center rounded-full ring-2',
                    s.badge,
                    ringClassName,
                    brand.tile,
                    brand.glyph,
                )}
            >
                {/* size-* class is required: shared item CSS force-sizes any
                    class-less svg to size-4 (16px). */}
                <PlatformGlyph
                    platform={platform as PlatformName}
                    className={cn(s.glyph, brand.glyph)}
                />
            </span>
        </span>
    );
}
