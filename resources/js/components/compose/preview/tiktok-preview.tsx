import { useRef, useState } from 'react';
import type { ReactNode } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Image as ImageIcon,
    Music2,
    Plus,
    Search,
} from '@/components/ui/icons';
import type { PlatformPreview } from '@/lib/compose/platform-preview';
import { LinkedText } from '@/lib/linked-text';
import { cn } from '@/lib/utils';
import type { MediaView, TikTokPostMode } from '@/types/compose';

import {
    clampIndex,
    handleName,
    previewInitials,
    previewMedia,
} from './helpers';
import { PreviewVideo } from './preview-video';
import {
    TikTokCommentIcon,
    TikTokLikeIcon,
    TikTokSaveIcon,
    TikTokShareIcon,
} from './tiktok-icons';

/** TikTok's brand red, used for the follow badge on the creator's avatar. */
const TIKTOK_RED = 'bg-[#FE2C55]';

/** Horizontal drag past this many pixels flips to the next/previous photo. */
const SWIPE_THRESHOLD = 40;

/**
 * TikTok draws #hashtags and @mentions in bold white rather than a link
 * color, so the caption reads like the For You feed.
 */
const TIKTOK_ENTITY_LINK = 'font-semibold text-white hover:opacity-80';

function RailAction({ icon, label }: { icon: ReactNode; label: string }) {
    return (
        <span className="flex flex-col items-center gap-0.5 drop-shadow">
            {icon}
            <span className="text-[10px] leading-3 font-semibold text-white/95">
                {label}
            </span>
        </span>
    );
}

function CreatorAvatar({
    preview,
    className,
}: {
    preview: PlatformPreview;
    className?: string;
}) {
    return (
        <Avatar className={className}>
            <AvatarImage src={preview.avatarUrl ?? undefined} />
            <AvatarFallback className="text-[9px] font-semibold text-foreground">
                {previewInitials(preview.accountName)}
            </AvatarFallback>
        </Avatar>
    );
}

/**
 * TikTok's right-hand rail, top to bottom: the creator's avatar with the red
 * follow badge, like, comment, save and share (counts start at zero for a new
 * post), and the spinning sound disc.
 */
function ActionRail({ preview }: { preview: PlatformPreview }) {
    return (
        <div className="flex shrink-0 flex-col items-center gap-3">
            <span className="relative mb-1.5">
                <CreatorAvatar
                    preview={preview}
                    className="size-8 ring-1 ring-white"
                />
                <span
                    className={cn(
                        'absolute -bottom-2 left-1/2 grid size-4 -translate-x-1/2 place-items-center rounded-full',
                        TIKTOK_RED,
                    )}
                >
                    <Plus className="size-3 text-white" aria-hidden />
                </span>
            </span>
            <RailAction
                icon={<TikTokLikeIcon className="size-6" />}
                label="0"
            />
            <RailAction
                icon={<TikTokCommentIcon className="size-6" />}
                label="0"
            />
            <RailAction
                icon={<TikTokSaveIcon className="size-[22px]" />}
                label="0"
            />
            <RailAction
                icon={<TikTokShareIcon className="size-6" />}
                label="Share"
            />
            <span
                data-slot="tiktok-sound-disc"
                className="mt-0.5 grid size-7 animate-[spin_6s_linear_infinite] place-items-center rounded-full bg-gradient-to-br from-neutral-600 to-neutral-950 motion-reduce:animate-none"
            >
                <CreatorAvatar preview={preview} className="size-3.5" />
            </span>
            <span className="sr-only">Like · Comment · Save · Share</span>
        </div>
    );
}

/**
 * A TikTok photo post: one photo at a time, swiped (or mouse-dragged)
 * sideways. Navigation stops at the first and last photo, like the app.
 */
function PhotoSlides({
    photos,
    index,
    onStep,
}: {
    photos: MediaView[];
    index: number;
    onStep: (delta: number) => void;
}) {
    const dragStartX = useRef<number | null>(null);
    const current = photos[index];

    if (!current) {
        return null;
    }

    return (
        <div
            data-slot="tiktok-photos"
            className="absolute inset-0 touch-pan-y select-none"
            onPointerDown={(event) => {
                dragStartX.current = event.clientX;
            }}
            onPointerUp={(event) => {
                if (dragStartX.current === null) {
                    return;
                }
                const dx = event.clientX - dragStartX.current;
                dragStartX.current = null;
                if (dx <= -SWIPE_THRESHOLD) {
                    onStep(1);
                } else if (dx >= SWIPE_THRESHOLD) {
                    onStep(-1);
                }
            }}
            onPointerCancel={() => {
                dragStartX.current = null;
            }}
        >
            <img
                key={current.id}
                src={current.url}
                alt={current.alt_text ?? ''}
                draggable={false}
                className="absolute inset-0 size-full object-cover"
            />
        </div>
    );
}

/**
 * A 9:16 TikTok For You preview: one video, or a photo post shown as
 * swipeable photos (the first is the cover the connector sends), with
 * TikTok's own chrome — the Following / For You header, the creator rail and
 * sound disc, and the bold username, caption and sound line. A video sent to
 * the inbox carries no caption — TikTok's inbox endpoint takes none — so the
 * preview leaves it off, matching what is sent.
 */
export function TikTokPreview({
    preview,
    postMode,
}: {
    preview: PlatformPreview;
    postMode: TikTokPostMode;
}) {
    const [photoIndex, setPhotoIndex] = useState(0);
    const media = previewMedia(preview);
    const video = media.find((item) => item.kind === 'video') ?? null;
    const photos = media.filter((item) => item.kind === 'image');
    const showPhotos = video === null && photos.length > 0;
    const manyPhotos = showPhotos && photos.length > 1;
    const safeIndex = clampIndex(photoIndex, photos.length);
    const item = preview.items[0];
    const captionSent = postMode === 'direct' || video === null;
    const caption = captionSent ? (item?.text ?? '') : '';
    const name = handleName(preview);

    const step = (delta: number) => {
        setPhotoIndex((previous) =>
            clampIndex(previous + delta, photos.length),
        );
    };

    return (
        <div className="p-4">
            <div className="mx-auto w-full max-w-[248px]">
                <div className="relative aspect-[9/16] overflow-hidden rounded-2xl bg-black text-white shadow-sm ring-1 ring-border">
                    {video ? (
                        <PreviewVideo
                            src={video.url}
                            className="absolute inset-0 size-full"
                            buttonClassName="top-10 right-2.5 bottom-auto"
                        />
                    ) : showPhotos ? (
                        <PhotoSlides
                            photos={photos}
                            index={safeIndex}
                            onStep={step}
                        />
                    ) : (
                        <div className="absolute inset-0 grid place-items-center bg-gradient-to-b from-[#103a3c] via-neutral-900 to-[#3f1020] text-center">
                            <div className="space-y-1.5 px-4 text-white/70">
                                <ImageIcon
                                    className="mx-auto size-6"
                                    aria-hidden
                                />
                                <p className="text-[12px] leading-4">
                                    Add a video or photos — TikTok posts need
                                    media.
                                </p>
                            </div>
                        </div>
                    )}

                    {/* Top: the For You feed header. */}
                    <div className="pointer-events-none absolute inset-x-0 top-0 bg-gradient-to-b from-black/45 to-transparent px-3 pt-2.5 pb-6">
                        <div className="flex items-center justify-center gap-3.5 text-[12px] font-semibold drop-shadow">
                            <span className="text-white/65">Following</span>
                            <span className="relative pb-1 text-white">
                                For You
                                <span className="absolute inset-x-3 -bottom-0.5 h-0.5 rounded-full bg-white" />
                            </span>
                        </div>
                        <Search
                            className="absolute top-2.5 right-3 size-4 drop-shadow"
                            aria-hidden
                        />
                    </div>

                    {/* Bottom: username, caption and sound beside the rail. */}
                    <div className="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/65 via-black/25 to-transparent pt-16 pr-2 pb-2.5 pl-3">
                        <div className="flex items-end gap-2">
                            <div className="min-w-0 flex-1 space-y-1 pb-0.5">
                                <p className="truncate text-[13px] leading-4 font-semibold drop-shadow">
                                    {name}
                                </p>
                                {caption !== '' && (
                                    <p className="pointer-events-auto line-clamp-3 text-[12px] leading-4 wrap-anywhere whitespace-pre-wrap text-white/95 drop-shadow">
                                        <LinkedText
                                            text={caption}
                                            platform="tiktok"
                                            linkExclusions={
                                                item?.linkExclusions ?? []
                                            }
                                            linkClassName={TIKTOK_ENTITY_LINK}
                                        />
                                    </p>
                                )}
                                <div className="flex items-center gap-1.5 text-[11px] leading-4 text-white/90 drop-shadow">
                                    <Music2
                                        className="size-3 shrink-0"
                                        aria-hidden
                                    />
                                    <span className="truncate">
                                        original sound - {name}
                                    </span>
                                </div>
                            </div>
                            <ActionRail preview={preview} />
                        </div>

                        {manyPhotos && (
                            <div className="pointer-events-auto mt-1.5 flex items-center justify-center">
                                {photos.map((photo, dot) => (
                                    <button
                                        key={photo.id}
                                        type="button"
                                        aria-label={`Go to photo ${dot + 1}`}
                                        aria-current={dot === safeIndex}
                                        onClick={() => setPhotoIndex(dot)}
                                        className="flex items-center justify-center px-[3px] py-0.5"
                                    >
                                        <span
                                            className={cn(
                                                'block size-1.5 rounded-full transition-colors',
                                                dot === safeIndex
                                                    ? 'bg-white'
                                                    : 'bg-white/40',
                                            )}
                                        />
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
            <p className="mt-3 text-[12px] leading-5 text-muted-foreground">
                {postMode === 'direct'
                    ? 'Posted to the TikTok profile. TikTok can take a few minutes to process it.'
                    : captionSent
                      ? "Sent to the creator's TikTok inbox as a draft to finish in the app."
                      : "Sent to the creator's TikTok inbox as a draft; the caption is added in the TikTok app."}
            </p>
        </div>
    );
}
