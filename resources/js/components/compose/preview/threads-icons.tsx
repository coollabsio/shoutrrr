/**
 * Threads' own action-bar glyphs, recreated from the shapes the Threads web app
 * uses (like, comment, repost, share) plus the verified seal. The action icons
 * render as outlines (stroke, no fill) exactly like the real feed; the verified
 * seal is the filled blue badge.
 */

type IconProps = { className?: string };

function StrokeIcon({
    className,
    viewBox,
    children,
}: IconProps & { viewBox: string; children: React.ReactNode }) {
    return (
        <svg
            viewBox={viewBox}
            fill="none"
            stroke="currentColor"
            strokeWidth={1.9}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={className}
            aria-hidden
        >
            {children}
        </svg>
    );
}

export function ThreadsLikeIcon({ className }: IconProps) {
    return (
        <StrokeIcon className={className} viewBox="0 0 24 22">
            <path d="M1 7.66c0 4.575 3.899 9.086 9.987 12.934.338.203.74.406 1.013.406.283 0 .686-.203 1.013-.406C19.1 16.746 23 12.234 23 7.66 23 3.736 20.245 1 16.672 1 14.603 1 12.98 1.94 12 3.352 11.042 1.952 9.408 1 7.328 1 3.766 1 1 3.736 1 7.66Z" />
        </StrokeIcon>
    );
}

export function ThreadsCommentIcon({ className }: IconProps) {
    return (
        <StrokeIcon className={className} viewBox="0 0 24 24">
            <path d="M20.656 17.008a9.993 9.993 0 1 0-3.59 3.615L22 22Z" />
        </StrokeIcon>
    );
}

export function ThreadsRepostIcon({ className }: IconProps) {
    return (
        <StrokeIcon className={className} viewBox="0 0 24 24">
            <path d="M19.998 9.497a1 1 0 0 0-1 1v4.228a3.274 3.274 0 0 1-3.27 3.27h-5.313l1.791-1.787a1 1 0 0 0-1.412-1.416L7.29 18.287a1.004 1.004 0 0 0-.294.707v.001c0 .023.012.042.013.065a.923.923 0 0 0 .281.643l3.502 3.504a1 1 0 0 0 1.414-1.414l-1.797-1.798h5.318a5.276 5.276 0 0 0 5.27-5.27v-4.228a1 1 0 0 0-1-1Zm-6.41-3.496-1.795 1.795a1 1 0 1 0 1.414 1.414l3.5-3.5a1.003 1.003 0 0 0 0-1.417l-3.5-3.5a1 1 0 0 0-1.414 1.414l1.794 1.794H8.27A5.277 5.277 0 0 0 3 9.271V13.5a1 1 0 0 0 2 0V9.271a3.275 3.275 0 0 1 3.271-3.27Z" />
        </StrokeIcon>
    );
}

export function ThreadsShareIcon({ className }: IconProps) {
    return (
        <StrokeIcon className={className} viewBox="0 0 24 24">
            <polygon points="11.698 20.334 22 3.001 2 3.001 9.218 10.084 11.698 20.334" />
        </StrokeIcon>
    );
}

export function ThreadsVerifiedIcon({ className }: IconProps) {
    return (
        <svg
            viewBox="0 0 40 40"
            className={className}
            fill="#0095F6"
            fillRule="evenodd"
            aria-label="Verified"
        >
            <path d="M19.998 3.094 14.638 0l-2.972 5.15H5.432v6.354L0 14.64 3.094 20 0 25.359l5.432 3.137v5.905h5.975L14.638 40l5.36-3.094L25.358 40l3.232-5.6h6.162v-6.01L40 25.359 36.905 20 40 14.641l-5.248-3.03v-6.46h-6.419L25.358 0l-5.36 3.094Zm7.415 11.225 2.254 2.287-11.43 11.5-6.835-6.93 2.244-2.258 4.587 4.581 9.18-9.18Z" />
        </svg>
    );
}
