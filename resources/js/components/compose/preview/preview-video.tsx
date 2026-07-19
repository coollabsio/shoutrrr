import { Volume2, VolumeX } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

type Props = {
    src: string;
    /** Wrapper element classes (position/size). */
    className?: string;
    /** `<video>` element classes; defaults to a cover fill. */
    videoClassName?: string;
    /** Position override for the mute toggle; defaults to bottom-right. */
    buttonClassName?: string;
    poster?: string;
};

/**
 * Autoplaying, looping, inline preview video that starts **muted** — matching
 * how Instagram and Facebook autoplay feed/story video — with a corner toggle to
 * unmute. Muting is driven imperatively through a ref because browsers only honor
 * autoplay while the media element is actually muted, and the attribute alone is
 * unreliable across React re-renders.
 */
export function PreviewVideo({
    src,
    className,
    videoClassName,
    buttonClassName,
    poster,
}: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [muted, setMuted] = useState(true);

    useEffect(() => {
        const video = videoRef.current;
        if (video) {
            video.muted = muted;
        }
    }, [muted]);

    return (
        <div className={cn('relative overflow-hidden', className)}>
            <video
                ref={videoRef}
                src={src}
                poster={poster}
                className={cn('size-full object-cover', videoClassName)}
                autoPlay
                loop
                muted
                playsInline
            />
            <button
                type="button"
                onClick={(event) => {
                    event.stopPropagation();
                    setMuted((value) => !value);
                }}
                aria-label={muted ? 'Unmute video' : 'Mute video'}
                aria-pressed={!muted}
                className={cn(
                    'absolute right-2 bottom-2 z-20 grid size-7 place-items-center rounded-full bg-black/55 text-white backdrop-blur-sm transition-colors hover:bg-black/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white',
                    buttonClassName,
                )}
            >
                {muted ? (
                    <VolumeX className="size-3.5" aria-hidden />
                ) : (
                    <Volume2 className="size-3.5" aria-hidden />
                )}
            </button>
        </div>
    );
}
