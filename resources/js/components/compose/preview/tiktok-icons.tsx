/**
 * TikTok's own right-rail glyphs (like, comment, save, share), recreated as the
 * solid white shapes the For You feed draws over the video. They fill with
 * currentColor; the comment bubble's three dots are cut out in the feed's dark
 * ink so they read on top of it.
 */

type IconProps = { className?: string };

function SolidIcon({
    className,
    children,
}: IconProps & { children: React.ReactNode }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            className={className}
            aria-hidden
        >
            {children}
        </svg>
    );
}

export function TikTokLikeIcon({ className }: IconProps) {
    return (
        <SolidIcon className={className}>
            <path d="M12 21.2c-.3 0-.6-.1-.8-.3C5.6 16.3 2 12.9 2 8.7 2 5.5 4.5 3 7.6 3c1.8 0 3.4.9 4.4 2.2C13 3.9 14.6 3 16.4 3 19.5 3 22 5.5 22 8.7c0 4.2-3.6 7.6-9.2 12.2-.2.2-.5.3-.8.3Z" />
        </SolidIcon>
    );
}

export function TikTokCommentIcon({ className }: IconProps) {
    return (
        <SolidIcon className={className}>
            <path d="M12 2.6c-5.4 0-9.8 3.9-9.8 8.7 0 2.8 1.5 5.3 3.8 6.9-.2 1.3-.7 2.6-1.8 3.7 2.5-.1 4.4-1 5.7-2 .7.1 1.4.2 2.1.2 5.4 0 9.8-3.9 9.8-8.8S17.4 2.6 12 2.6Z" />
            <g fill="#161823" fillOpacity={0.75}>
                <circle cx="7.8" cy="11.3" r="1.3" />
                <circle cx="12" cy="11.3" r="1.3" />
                <circle cx="16.2" cy="11.3" r="1.3" />
            </g>
        </SolidIcon>
    );
}

export function TikTokSaveIcon({ className }: IconProps) {
    return (
        <SolidIcon className={className}>
            <path d="M6.2 2.8h11.6c.8 0 1.4.6 1.4 1.4v16.7c0 .6-.7.9-1.1.6L12 16.9l-6.1 4.6c-.4.3-1.1 0-1.1-.6V4.2c0-.8.6-1.4 1.4-1.4Z" />
        </SolidIcon>
    );
}

export function TikTokShareIcon({ className }: IconProps) {
    return (
        <SolidIcon className={className}>
            <path d="M13.4 4.3v4C6.8 8.9 3.2 13.2 2.9 19.5c0 .4.4.5.6.2 1.8-3.2 4.9-4.9 9.9-5v4.1c0 .5.5.7.9.4l7.4-6.9c.3-.3.3-.7 0-1l-7.4-6.9c-.4-.3-.9-.1-.9.4Z" />
        </SolidIcon>
    );
}
