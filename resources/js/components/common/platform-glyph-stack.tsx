import { PlatformGlyph } from '@/components/common/platform-glyph';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

type Props = {
    platforms: PlatformName[];
    targetCount: number;
    size?: number;
    className?: string;
};

const MAX_GLYPHS = 3;

/**
 * A compact multi-account indicator: up to three platform glyphs side by side
 * plus a `+n` remainder. Unlike the overlapped, ringed tiles in `PostRow`, this
 * is a flat cluster with no boxes or background — calendar chips are only 20px
 * tall and sit on a translucent tinted fill, where ringed tiles smear together
 * and outgrow the chip. The remainder covers both extra platforms and multiple
 * accounts on the same platform, so `['x']` with two targets reads `X +1`.
 */
export function PlatformGlyphStack({
    platforms,
    targetCount,
    size,
    className,
}: Props) {
    if (platforms.length === 0 && targetCount <= 0) {
        return null;
    }

    const shownGlyphs = platforms.slice(0, MAX_GLYPHS);
    const extra = Math.max(0, targetCount - shownGlyphs.length);

    return (
        <span
            aria-label={`${targetCount} ${targetCount === 1 ? 'account' : 'accounts'}`}
            className={cn('inline-flex items-center gap-[3px]', className)}
        >
            {shownGlyphs.map((platform) => (
                <PlatformGlyph
                    key={platform}
                    platform={platform}
                    size={size}
                    className="shrink-0"
                />
            ))}
            {extra > 0 && (
                <span className="shrink-0 tabular-nums">+{extra}</span>
            )}
        </span>
    );
}
