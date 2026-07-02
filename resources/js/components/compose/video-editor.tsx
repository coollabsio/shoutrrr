import { Crop, Pause, Play, Volume1, Volume2, VolumeX, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { centeredCropForRatio } from '@/lib/image-editor/layout';
import type { CropRect } from '@/lib/image-editor/settings';
import { cn } from '@/lib/utils';
import {
    VIDEO_ASPECT_PRESETS,
    type VideoAspectPreset,
    videoAspectToRatio,
} from '@/lib/video-editor/aspects';
import {
    defaultSettings,
    type VideoEditSettings,
} from '@/lib/video-editor/settings';
import { firstEncodableVideoCodec } from '@/lib/video-editor/support';

import { VideoCropOverlay } from './video-crop-overlay';

type Props = {
    open: boolean;
    sourceUrl: string | null;
    durationSeconds: number;
    onApply: (
        settings: VideoEditSettings,
        altText: string,
    ) => Promise<void> | void;
    onCancel: () => void;
    /** 'new' = a just-added video (offer "Upload without editing"); 'existing' = re-editing an uploaded video. */
    variant?: 'new' | 'existing';
    /** Called when the user chooses to upload without editing (only relevant for 'new' variant). */
    onSkip?: () => void;
    phase: 'idle' | 'rendering' | 'compressing' | 'uploading';
    /** 0..1 — shown as a progress bar while phase !== 'idle'. */
    progress: number;
    /** Initial alt text: persisted value on re-edit, empty for a fresh video. */
    initialAltText?: string | null;
};

/** Minimum gap enforced between the in and out trim handles (seconds). */
const MIN_TRIM_GAP = 0.1;

/** Keyboard step size for the trim handles (seconds). Shift multiplies by 10. */
const STEP = 0.1;

/** Fallback display size before the preview container is measured. */
const DISPLAY_FALLBACK = 420;

/** Format a duration as MM:SS, truncating to whole seconds. */
function fmtMmSs(totalSeconds: number): string {
    const s = Math.max(0, Math.floor(totalSeconds));
    const m = Math.floor(s / 60);
    return `${String(m).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

export function VideoEditor({
    open,
    sourceUrl,
    durationSeconds,
    onApply,
    onCancel,
    variant = 'existing',
    onSkip,
    phase,
    progress,
    initialAltText = '',
}: Props) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const trackRef = useRef<HTMLDivElement | null>(null);
    const [previewEl, setPreviewEl] = useState<HTMLDivElement | null>(null);
    const [settings, setSettings] = useState<VideoEditSettings>(() =>
        defaultSettings(durationSeconds),
    );
    const [sourceSize, setSourceSize] = useState<{
        width: number;
        height: number;
    } | null>(null);
    const [box, setBox] = useState<{ w: number; h: number }>({ w: 0, h: 0 });
    const [playing, setPlaying] = useState(false);
    const [currentTime, setCurrentTime] = useState(0);
    const [volume, setVolume] = useState(1);
    const [muted, setMuted] = useState(false);
    const [altText, setAltText] = useState(initialAltText ?? '');
    // Whether the browser can actually re-encode at this clip's resolution.
    // Cropping forces a re-encode; trimming only copies the track, so the editor
    // always offers trim but hides the crop tools when encoding isn't possible.
    const [canCrop, setCanCrop] = useState(false);
    // The duration prop is floored (it doubles as the platform-limit check), so
    // relying on it for the trim range would silently drop the sub-second tail.
    // Once the <video> loads we switch to its precise duration.
    const [mediaDuration, setMediaDuration] = useState(durationSeconds);

    const busy = phase !== 'idle';
    // Guard against divide-by-zero when duration hasn't been resolved yet.
    const safeD = mediaDuration > 0 ? mediaDuration : 1;

    // Compute on-screen video dimensions from the measured canvas area.
    const displayScale = sourceSize
        ? Math.min(
              (box.w || DISPLAY_FALLBACK) / sourceSize.width,
              (box.h || DISPLAY_FALLBACK) / sourceSize.height,
          )
        : 1;
    const dispW = sourceSize
        ? sourceSize.width * displayScale
        : DISPLAY_FALLBACK;
    const dispH = sourceSize
        ? sourceSize.height * displayScale
        : DISPLAY_FALLBACK;

    // Reset settings and clear any previously loaded source size whenever the
    // modal opens or the video source changes (new upload, different clip).
    useEffect(() => {
        if (!open) {
            videoRef.current?.pause();
            return;
        }
        setSettings(defaultSettings(durationSeconds));
        setSourceSize(null);
        setMediaDuration(durationSeconds);
        setPlaying(false);
        setCurrentTime(0);
    }, [open, durationSeconds]);

    // Observe the preview canvas so dispW/dispH stay in sync with the container.
    useEffect(() => {
        if (!previewEl) {
            return;
        }
        const ro = new ResizeObserver((entries) => {
            const rect = entries[0]?.contentRect;
            if (rect) {
                setBox({ w: rect.width, h: rect.height });
            }
        });
        ro.observe(previewEl);
        return () => ro.disconnect();
    }, [previewEl]);

    // Keep the preview element's audio in sync with the volume control. Setting
    // these via the DOM property (not the `muted` attribute, which React applies
    // unreliably) and re-running when the source mounts ensures it always takes.
    useEffect(() => {
        if (videoRef.current) {
            videoRef.current.volume = volume;
            videoRef.current.muted = muted;
        }
    }, [volume, muted, sourceUrl]);

    // Probe real encode capability at the clip's actual resolution once it's
    // known. A real frame is encoded (isConfigSupported lies on some builds), so
    // the crop tools only appear when cropping will genuinely work.
    useEffect(() => {
        setCanCrop(false);
        if (!open || !sourceSize) {
            return;
        }
        let active = true;
        void firstEncodableVideoCodec(sourceSize.width, sourceSize.height).then(
            (codec) => {
                if (active) {
                    setCanCrop(codec !== null);
                }
            },
        );
        return () => {
            active = false;
        };
    }, [open, sourceSize]);

    // If an aspect was picked before the natural size was known, selectAspect
    // couldn't seed the crop rect. Seed it once the size arrives so the export
    // matches the overlay the user sees (otherwise render gets crop: null and
    // exports the full, uncropped frame).
    useEffect(() => {
        if (!sourceSize) {
            return;
        }
        setSettings((s) => {
            if (s.aspect === 'auto' || s.crop) {
                return s;
            }
            const ratio = videoAspectToRatio(s.aspect);
            const crop =
                ratio !== null
                    ? centeredCropForRatio(
                          sourceSize.width,
                          sourceSize.height,
                          ratio,
                      )
                    : {
                          x: 0,
                          y: 0,
                          width: sourceSize.width,
                          height: sourceSize.height,
                      };
            return { ...s, crop };
        });
    }, [sourceSize]);

    /** Apply a new aspect preset, seeding or clearing the crop rect as needed. */
    function selectAspect(aspect: VideoAspectPreset) {
        const ratio = videoAspectToRatio(aspect);
        setSettings((s) => {
            let crop: CropRect | null = s.crop;
            if (aspect === 'auto') {
                // No crop at all — clear it.
                crop = null;
            } else if (aspect !== 'freeform' && ratio !== null && sourceSize) {
                // Seed a centred crop rect that matches the selected ratio.
                crop = centeredCropForRatio(
                    sourceSize.width,
                    sourceSize.height,
                    ratio,
                );
            } else if (aspect === 'freeform' && !s.crop && sourceSize) {
                // Entering freeform with no existing crop — default to the full frame.
                crop = {
                    x: 0,
                    y: 0,
                    width: sourceSize.width,
                    height: sourceSize.height,
                };
            }
            return { ...s, aspect, crop };
        });
    }

    /**
     * Translate a pointer event's clientX into a time within [0, durationSeconds]
     * by measuring against the timeline track element.
     */
    function seekFromPointer(e: React.PointerEvent): number {
        if (!trackRef.current) {
            return 0;
        }
        const rect = trackRef.current.getBoundingClientRect();
        const fraction = Math.max(
            0,
            Math.min(1, (e.clientX - rect.left) / rect.width),
        );
        return fraction * safeD;
    }

    // The crop overlay is shown only once the natural video size is known so
    // the VideoCropOverlay can scale correctly. Until then the plain <video>
    // renders and fires onLoadedMetadata to supply the size.
    const showOverlay =
        canCrop &&
        settings.aspect !== 'auto' &&
        sourceSize !== null &&
        sourceUrl !== null;

    const altTextField = (
        <Field label="Alt text">
            <textarea
                value={altText}
                onChange={(e) => setAltText(e.target.value)}
                placeholder="Describe the video for accessibility"
                rows={3}
                maxLength={1000}
                className="w-full resize-none rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:ring-2 focus:ring-foreground/20 focus:outline-none"
            />
            <p className="text-[11px] text-muted-foreground">
                Describe the video for screen readers
            </p>
        </Field>
    );

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onCancel();
                }
            }}
        >
            <DialogContent
                showCloseButton={false}
                className="flex h-dvh w-full max-w-none flex-col gap-0 overflow-hidden rounded-none p-0 sm:h-[85vh] sm:max-h-[760px] sm:w-[min(1080px,95vw)] sm:max-w-none sm:rounded-[min(var(--radius-4xl),24px)]"
            >
                {/* ── Header ────────────────────────────────────────────── */}
                <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
                    <DialogTitle className="text-sm font-semibold">
                        Edit video
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Trim and crop the video before attaching it to your
                        post.
                    </DialogDescription>
                    <button
                        type="button"
                        aria-label="Close"
                        onClick={onCancel}
                        className="-mr-1 grid size-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <X className="size-4" aria-hidden="true" />
                    </button>
                </header>

                {/* ── Body: canvas + inspector (stacked → side-by-side) ── */}
                <div className="flex min-h-0 flex-1 flex-col md:flex-row">
                    {/* Canvas: preview above, timeline strip below. md:min-w-0 +
                        md:shrink let the pane yield width when the crop sidebar
                        mounts after its async capability probe — without them the
                        sidebar spills past the dialog's clipped edge. */}
                    <section className="flex h-[50dvh] min-h-0 shrink-0 flex-col md:h-auto md:min-w-0 md:flex-1 md:shrink">
                        {/* Video preview */}
                        <div
                            ref={setPreviewEl}
                            className="relative grid flex-1 place-items-center overflow-hidden bg-black"
                        >
                            {!sourceUrl ? (
                                /* No source yet — show a spinner */
                                <div className="size-7 animate-spin rounded-full border-2 border-white/40 border-t-transparent" />
                            ) : (
                                /* Single <video> for both plain and crop modes — never remounts on
                                   the sourceSize null→non-null transition; only the wrapper style
                                   and video className change conditionally. */
                                <div
                                    className="relative"
                                    style={
                                        sourceSize
                                            ? { width: dispW, height: dispH }
                                            : undefined
                                    }
                                >
                                    <video
                                        ref={videoRef}
                                        src={sourceUrl}
                                        playsInline
                                        preload="metadata"
                                        className={
                                            sourceSize
                                                ? 'block size-full object-fill'
                                                : 'block max-h-full max-w-full object-contain'
                                        }
                                        onLoadedMetadata={(e) => {
                                            const v = e.currentTarget;
                                            setSourceSize({
                                                width: v.videoWidth,
                                                height: v.videoHeight,
                                            });
                                            // Adopt the element's precise duration. If the trim
                                            // is still the untouched full clip, extend its end so
                                            // an immediate Apply keeps the sub-second tail the
                                            // floored prop would have dropped.
                                            if (
                                                Number.isFinite(v.duration) &&
                                                v.duration > 0
                                            ) {
                                                setMediaDuration(v.duration);
                                                setSettings((s) =>
                                                    s.trim.start === 0 &&
                                                    s.trim.end ===
                                                        durationSeconds
                                                        ? {
                                                              ...s,
                                                              trim: {
                                                                  ...s.trim,
                                                                  end: v.duration,
                                                              },
                                                          }
                                                        : s,
                                                );
                                            }
                                        }}
                                        onTimeUpdate={(e) => {
                                            const video = e.currentTarget;
                                            const t = video.currentTime;
                                            setCurrentTime(t);
                                            // Loop within the selected trim range.
                                            if (t >= settings.trim.end) {
                                                video.currentTime =
                                                    settings.trim.start;
                                            } else if (
                                                t < settings.trim.start
                                            ) {
                                                video.currentTime =
                                                    settings.trim.start;
                                            }
                                        }}
                                        onPause={() => setPlaying(false)}
                                        onPlay={() => setPlaying(true)}
                                    />
                                    {showOverlay && (
                                        <VideoCropOverlay
                                            sourceSize={sourceSize!}
                                            dispW={dispW}
                                            rect={
                                                settings.crop ?? {
                                                    x: 0,
                                                    y: 0,
                                                    width: sourceSize!.width,
                                                    height: sourceSize!.height,
                                                }
                                            }
                                            ratio={videoAspectToRatio(
                                                settings.aspect,
                                            )}
                                            onChange={(rect) =>
                                                setSettings((s) => ({
                                                    ...s,
                                                    crop: rect,
                                                }))
                                            }
                                        />
                                    )}
                                </div>
                            )}

                            {/* Loading overlay — covers the blank frame while the source
                                loads (notably when reopening an uploaded clip whose bytes
                                are fetched over the network). sourceSize resets to null on
                                open and is set on loadedmetadata, so this is the load window. */}
                            {sourceUrl && !sourceSize && (
                                <div className="pointer-events-none absolute inset-0 z-40 grid place-items-center bg-black">
                                    <div className="size-7 animate-spin rounded-full border-2 border-white/40 border-t-transparent" />
                                </div>
                            )}

                            {/* Preview volume — native-player chrome over the video; affects
                                playback only, never the exported clip (audio is always kept). */}
                            {sourceUrl && sourceSize && (
                                <div
                                    className={cn(
                                        'absolute right-2 bottom-2 z-30 flex items-center gap-1 rounded-full bg-black/55 py-1 pr-2.5 pl-1 text-white backdrop-blur-sm transition-opacity',
                                        busy && 'pointer-events-none opacity-0',
                                    )}
                                >
                                    <button
                                        type="button"
                                        aria-label={
                                            muted || volume === 0
                                                ? 'Unmute preview'
                                                : 'Mute preview'
                                        }
                                        onClick={() => {
                                            if (muted) {
                                                setMuted(false);
                                                if (volume === 0) {
                                                    setVolume(1);
                                                }
                                            } else {
                                                setMuted(true);
                                            }
                                        }}
                                        className="grid size-7 shrink-0 place-items-center rounded-full text-white/90 transition-colors hover:text-white"
                                    >
                                        {muted || volume === 0 ? (
                                            <VolumeX
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        ) : volume < 0.5 ? (
                                            <Volume1
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Volume2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        )}
                                    </button>
                                    <input
                                        type="range"
                                        min={0}
                                        max={1}
                                        step={0.05}
                                        value={muted ? 0 : volume}
                                        aria-label="Preview volume"
                                        onChange={(e) => {
                                            const v = Number(e.target.value);
                                            setVolume(v);
                                            setMuted(v === 0);
                                        }}
                                        className="h-1 w-16 cursor-pointer accent-white"
                                    />
                                </div>
                            )}
                        </div>

                        {/* ── Timeline trim strip ───────────────────────── */}
                        <div
                            className={cn(
                                'shrink-0 border-t border-border bg-muted/20 px-4 pt-3 pb-4',
                                busy && 'pointer-events-none opacity-50',
                            )}
                        >
                            {/* Play/Pause button + Track. Align to the top so the play
                                button lines up with the track, not the track+labels column. */}
                            <div className="flex items-start gap-3">
                                {/* Play/Pause button */}
                                <button
                                    type="button"
                                    aria-label={
                                        playing ? 'Pause' : 'Play selection'
                                    }
                                    disabled={busy || !sourceUrl}
                                    onClick={() => {
                                        if (!videoRef.current) {
                                            return;
                                        }
                                        if (playing) {
                                            videoRef.current.pause();
                                        } else {
                                            if (
                                                currentTime <
                                                    settings.trim.start ||
                                                currentTime >= settings.trim.end
                                            ) {
                                                videoRef.current.currentTime =
                                                    settings.trim.start;
                                            }
                                            void videoRef.current.play();
                                        }
                                    }}
                                    className="grid size-8 shrink-0 place-items-center rounded-full border border-border text-foreground transition-colors hover:bg-muted disabled:opacity-50"
                                >
                                    {playing ? (
                                        <Pause
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <Play
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    )}
                                </button>

                                {/* Track + time labels share a column so the labels stay aligned with the track */}
                                <div className="min-w-0 flex-1">
                                    {/* Track */}
                                    <div
                                        ref={trackRef}
                                        className="relative h-8 w-full touch-none rounded-lg bg-foreground/[0.07] select-none"
                                        onPointerDown={(e) => {
                                            // Click-to-seek: handles stop propagation so this only
                                            // fires when clicking the track background or range fill.
                                            const time = seekFromPointer(e);
                                            if (videoRef.current) {
                                                videoRef.current.currentTime =
                                                    time;
                                            }
                                            setCurrentTime(time);
                                        }}
                                    >
                                        {/* Selected-range highlight */}
                                        <div
                                            className="pointer-events-none absolute inset-y-0 rounded-lg bg-foreground/[0.16]"
                                            style={{
                                                left: `${(settings.trim.start / safeD) * 100}%`,
                                                right: `${(1 - settings.trim.end / safeD) * 100}%`,
                                            }}
                                        />

                                        {/* Playhead */}
                                        <div
                                            className="pointer-events-none absolute inset-y-0 z-20 w-0.5 -translate-x-1/2 bg-foreground/80"
                                            style={{
                                                left: `${Math.max(0, Math.min(100, (currentTime / safeD) * 100))}%`,
                                            }}
                                        />

                                        {/* In-point (start) handle */}
                                        <div
                                            role="slider"
                                            aria-label="Trim start"
                                            aria-valuemin={0}
                                            aria-valuemax={safeD}
                                            aria-valuenow={settings.trim.start}
                                            aria-valuetext={fmtMmSs(
                                                settings.trim.start,
                                            )}
                                            tabIndex={0}
                                            className="absolute inset-y-0 z-10 w-1 -translate-x-1/2 cursor-ew-resize touch-none rounded-sm bg-foreground shadow-sm"
                                            style={{
                                                left: `${(settings.trim.start / safeD) * 100}%`,
                                            }}
                                            onKeyDown={(e) => {
                                                const step = e.shiftKey
                                                    ? 1
                                                    : STEP;
                                                let next: number | null = null;
                                                if (
                                                    e.key === 'ArrowLeft' ||
                                                    e.key === 'ArrowDown'
                                                ) {
                                                    e.preventDefault();
                                                    next =
                                                        settings.trim.start -
                                                        step;
                                                } else if (
                                                    e.key === 'ArrowRight' ||
                                                    e.key === 'ArrowUp'
                                                ) {
                                                    e.preventDefault();
                                                    next =
                                                        settings.trim.start +
                                                        step;
                                                } else if (e.key === 'Home') {
                                                    e.preventDefault();
                                                    next = 0;
                                                } else if (e.key === 'End') {
                                                    e.preventDefault();
                                                    next =
                                                        settings.trim.end -
                                                        MIN_TRIM_GAP;
                                                }
                                                if (next !== null) {
                                                    const start = Math.max(
                                                        0,
                                                        Math.min(
                                                            settings.trim.end -
                                                                MIN_TRIM_GAP,
                                                            next,
                                                        ),
                                                    );
                                                    setSettings((s) => ({
                                                        ...s,
                                                        trim: {
                                                            ...s.trim,
                                                            start,
                                                        },
                                                    }));
                                                    if (videoRef.current) {
                                                        videoRef.current.currentTime =
                                                            start;
                                                    }
                                                }
                                            }}
                                            onPointerDown={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                e.currentTarget.setPointerCapture(
                                                    e.pointerId,
                                                );
                                                videoRef.current?.pause();
                                            }}
                                            onPointerMove={(e) => {
                                                if (
                                                    !(
                                                        e.currentTarget as Element
                                                    ).hasPointerCapture(
                                                        e.pointerId,
                                                    )
                                                ) {
                                                    return;
                                                }
                                                const time = seekFromPointer(e);
                                                const start = Math.min(
                                                    time,
                                                    settings.trim.end -
                                                        MIN_TRIM_GAP,
                                                );
                                                setSettings((s) => ({
                                                    ...s,
                                                    trim: { ...s.trim, start },
                                                }));
                                                if (videoRef.current) {
                                                    videoRef.current.currentTime =
                                                        start;
                                                }
                                            }}
                                        >
                                            {/* Circular grip — larger touch target */}
                                            <div className="absolute top-1/2 left-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow" />
                                        </div>

                                        {/* Out-point (end) handle */}
                                        <div
                                            role="slider"
                                            aria-label="Trim end"
                                            aria-valuemin={0}
                                            aria-valuemax={safeD}
                                            aria-valuenow={settings.trim.end}
                                            aria-valuetext={fmtMmSs(
                                                settings.trim.end,
                                            )}
                                            tabIndex={0}
                                            className="absolute inset-y-0 z-10 w-1 -translate-x-1/2 cursor-ew-resize touch-none rounded-sm bg-foreground shadow-sm"
                                            style={{
                                                left: `${(settings.trim.end / safeD) * 100}%`,
                                            }}
                                            onKeyDown={(e) => {
                                                const step = e.shiftKey
                                                    ? 1
                                                    : STEP;
                                                let next: number | null = null;
                                                if (
                                                    e.key === 'ArrowLeft' ||
                                                    e.key === 'ArrowDown'
                                                ) {
                                                    e.preventDefault();
                                                    next =
                                                        settings.trim.end -
                                                        step;
                                                } else if (
                                                    e.key === 'ArrowRight' ||
                                                    e.key === 'ArrowUp'
                                                ) {
                                                    e.preventDefault();
                                                    next =
                                                        settings.trim.end +
                                                        step;
                                                } else if (e.key === 'Home') {
                                                    e.preventDefault();
                                                    next =
                                                        settings.trim.start +
                                                        MIN_TRIM_GAP;
                                                } else if (e.key === 'End') {
                                                    e.preventDefault();
                                                    next = safeD;
                                                }
                                                if (next !== null) {
                                                    const end = Math.max(
                                                        settings.trim.start +
                                                            MIN_TRIM_GAP,
                                                        Math.min(safeD, next),
                                                    );
                                                    setSettings((s) => ({
                                                        ...s,
                                                        trim: {
                                                            ...s.trim,
                                                            end,
                                                        },
                                                    }));
                                                    if (videoRef.current) {
                                                        videoRef.current.currentTime =
                                                            end;
                                                    }
                                                }
                                            }}
                                            onPointerDown={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                e.currentTarget.setPointerCapture(
                                                    e.pointerId,
                                                );
                                                videoRef.current?.pause();
                                            }}
                                            onPointerMove={(e) => {
                                                if (
                                                    !(
                                                        e.currentTarget as Element
                                                    ).hasPointerCapture(
                                                        e.pointerId,
                                                    )
                                                ) {
                                                    return;
                                                }
                                                const time = seekFromPointer(e);
                                                const end = Math.max(
                                                    time,
                                                    settings.trim.start +
                                                        MIN_TRIM_GAP,
                                                );
                                                setSettings((s) => ({
                                                    ...s,
                                                    trim: { ...s.trim, end },
                                                }));
                                                if (videoRef.current) {
                                                    videoRef.current.currentTime =
                                                        end;
                                                }
                                            }}
                                        >
                                            {/* Circular grip */}
                                            <div className="absolute top-1/2 left-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow" />
                                        </div>
                                    </div>

                                    {/* Time labels: start · selected duration · end */}
                                    <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground tabular-nums">
                                        <span>
                                            {fmtMmSs(settings.trim.start)}
                                        </span>
                                        <span className="font-medium text-foreground">
                                            {fmtMmSs(
                                                settings.trim.end -
                                                    settings.trim.start,
                                            )}
                                        </span>
                                        <span>
                                            {fmtMmSs(settings.trim.end)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    {/* ── Inspector rail ──────────────────────────────────── */}
                    {/* Crop tools — hidden when the browser can't re-encode, so
                        only trimming (a no-encode track copy) is offered. */}
                    {canCrop && (
                        <aside className="flex min-h-0 w-full flex-1 flex-col gap-5 overflow-y-auto border-t border-border p-5 md:w-[288px] md:flex-none md:border-t-0 md:border-l">
                            <Field label="Aspect ratio">
                                <div className="grid grid-cols-3 gap-1 rounded-lg bg-muted/60 p-1">
                                    {VIDEO_ASPECT_PRESETS.map(
                                        ({ value, label }) => (
                                            <Segment
                                                key={value}
                                                active={
                                                    settings.aspect === value
                                                }
                                                disabled={busy}
                                                onClick={() =>
                                                    selectAspect(value)
                                                }
                                            >
                                                {label}
                                            </Segment>
                                        ),
                                    )}
                                </div>
                            </Field>

                            {/* Shortcut to exit crop mode without touching the segmented control */}
                            {settings.aspect !== 'auto' && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => selectAspect('auto')}
                                    className="flex w-full items-center justify-center gap-2 rounded-lg border border-border py-2 text-sm font-medium transition-colors hover:bg-muted disabled:opacity-50"
                                >
                                    <Crop
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Clear crop
                                </button>
                            )}

                            {altTextField}
                        </aside>
                    )}

                    {/* Alt text field shown when there's no crop sidebar */}
                    {!canCrop && (
                        <aside className="flex min-h-0 w-full flex-1 flex-col gap-5 overflow-y-auto border-t border-border p-5 md:w-[288px] md:flex-none md:border-t-0 md:border-l">
                            {altTextField}
                        </aside>
                    )}
                </div>

                {/* ── Footer ────────────────────────────────────────────── */}
                <footer className="relative flex shrink-0 items-center gap-2 border-t border-border px-4 py-3 sm:px-5">
                    {/* Thin progress bar runs across the very top edge of the footer */}
                    {busy && (
                        <div className="absolute inset-x-0 top-0 h-0.5 overflow-hidden bg-muted">
                            <div
                                className="h-full bg-foreground transition-[width] duration-200 ease-linear"
                                style={{
                                    width: `${Math.max(0, Math.min(1, progress)) * 100}%`,
                                }}
                            />
                        </div>
                    )}

                    {/* Phase label — only visible while processing */}
                    {busy && (
                        <span className="text-sm text-muted-foreground">
                            {phase === 'rendering'
                                ? 'Rendering…'
                                : phase === 'compressing'
                                  ? 'Compressing…'
                                  : 'Uploading…'}
                        </span>
                    )}

                    <div className="ml-auto flex items-center gap-2">
                        {variant === 'new' ? (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={onSkip}
                                className="rounded-md px-3 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50 md:py-2"
                            >
                                Upload without editing
                            </button>
                        ) : (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={onCancel}
                                className="rounded-md px-3 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50 md:py-2"
                            >
                                Cancel
                            </button>
                        )}
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => {
                                void onApply(settings, altText);
                            }}
                            className="rounded-md bg-foreground px-4 py-2.5 text-sm font-medium text-background transition-opacity hover:opacity-90 disabled:opacity-50 md:py-2"
                        >
                            Apply
                        </button>
                    </div>
                </footer>
            </DialogContent>
        </Dialog>
    );
}

/* ── Small reusable sub-components ─────────────────────────────────────── */

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="space-y-2">
            <div className="text-xs font-medium text-muted-foreground">
                {label}
            </div>
            {children}
        </div>
    );
}

function Segment({
    active,
    onClick,
    children,
    disabled,
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'rounded-md py-2 text-center text-sm font-medium transition-colors md:py-1.5 md:text-xs',
                active
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
                disabled && 'pointer-events-none opacity-50',
            )}
        >
            {children}
        </button>
    );
}
