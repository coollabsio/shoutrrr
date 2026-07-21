import { ChevronDown, Crop, Trash2, Wand2, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cropToBlob, loadImage } from '@/lib/image-editor/crop';
import { rasterizeStage } from '@/lib/image-editor/export';
import {
    GRADIENTS,
    gradientToFill,
    NO_BACKGROUND,
} from '@/lib/image-editor/gradients';
import {
    aspectToRatio,
    centeredCropForRatio,
    clampCropRect,
    stageDimensions,
} from '@/lib/image-editor/layout';
import {
    ASPECT_PRESETS,
    type AspectPreset,
    type EditSettings,
    SHADOW_PRESETS,
    ZOOM_MAX,
    ZOOM_MIN,
} from '@/lib/image-editor/settings';
import { cn } from '@/lib/utils';

import { CropOverlay } from './crop-overlay';
import { ImageStage } from './image-stage';

type Props = {
    open: boolean;
    /** Source image to edit — an object URL or same-origin URL. The PARENT owns its lifecycle. */
    sourceUrl: string | null;
    /** Initial settings: defaults for a fresh image, persisted settings on re-edit. */
    initialSettings: EditSettings;
    /** Initial alt text: persisted value on re-edit, empty for a fresh image. */
    initialAltText?: string | null;
    /**
     * Compose + persist the result. The parent decides what to upload (new vs
     * replace) and advances the queue / closes the modal afterwards.
     */
    onApply: (
        composed: Blob,
        settings: EditSettings,
        altText: string,
    ) => Promise<void> | void;
    /** Keep the image without editing (attach the original on a fresh upload; close on re-edit). */
    onCancel: () => void;
    /** Discard entirely — don't attach a fresh upload / remove an existing image. */
    onDiscard: () => void;
    /** 'new' = a just-uploaded image (offer "Continue without editing"); 'existing' = re-editing. */
    variant: 'new' | 'existing';
    /** True while an upload triggered by onApply is in flight. */
    isSaving: boolean;
    /** Thumbnails of a multi-image batch + the index being edited, shown as a strip. */
    queue?: { thumbnails: string[]; index: number };
};

export function ImageEditor({
    open,
    sourceUrl,
    initialSettings,
    initialAltText = '',
    onApply,
    onCancel,
    onDiscard,
    variant,
    isSaving,
    queue,
}: Props) {
    const stageRef = useRef<HTMLDivElement | null>(null);
    const croppedUrlRef = useRef<string | null>(null);
    // Primary footer action — focused on open so Enter accepts the upload.
    const primaryButtonRef = useRef<HTMLButtonElement | null>(null);
    // Only auto-focus once per open/image; never steal focus mid-edit.
    const hasFocusedPrimaryRef = useRef(false);
    // Tracks whether picking a background has already styled this image, so the
    // one-time padding/radius/shadow defaults are applied on the FIRST background
    // choice only — never re-imposed if the user later dials them back.
    const hasAutoStyledRef = useRef(false);
    const [previewEl, setPreviewEl] = useState<HTMLDivElement | null>(null);
    const [settings, setSettings] = useState<EditSettings>(initialSettings);
    const [sourceImg, setSourceImg] = useState<HTMLImageElement | null>(null);
    // The cropped image as an object-URL fed to the stage; null until prepared.
    const [croppedUrl, setCroppedUrl] = useState<string | null>(null);
    const [cropMode, setCropMode] = useState(false);
    const [advanced, setAdvanced] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [box, setBox] = useState<{ w: number; h: number }>({ w: 0, h: 0 });
    const [altText, setAltText] = useState(initialAltText ?? '');

    // Measure the canvas so the image scales to fit it at any modal size. Keyed on
    // the element (a callback ref) rather than `open`, so it observes the moment the
    // canvas mounts — the `open`-keyed version could miss the measurement on mobile.
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

    // (Re)load the source and reset settings whenever the edited image changes
    // — covers both a fresh open and advancing to the next queued image.
    useEffect(() => {
        if (!sourceUrl) {
            return;
        }
        setSettings(initialSettings);
        setAltText(initialAltText ?? '');
        // An image opened with existing padding/radius/shadow is already styled,
        // so a background change must not re-apply the one-time defaults.
        hasAutoStyledRef.current =
            initialSettings.padding > 0 ||
            initialSettings.radius > 0 ||
            initialSettings.shadow !== 'none';
        setSourceImg(null);
        setCropMode(false);
        setLoadError(false);
        let revoked = false;
        loadImage(sourceUrl)
            .then((img) => {
                if (!revoked) {
                    setSourceImg(img);
                }
            })
            .catch(() => {
                if (!revoked) {
                    setLoadError(true);
                }
            });

        return () => {
            revoked = true;
        };
    }, [sourceUrl, initialSettings, initialAltText]);

    // Recompute the cropped image whenever the source or crop rect changes.
    // Skipped while cropping: the crop overlay (not the cropped output) is shown
    // then, so re-encoding the full-resolution PNG on every drag tick is wasted
    // work — it runs once when crop mode is committed.
    useEffect(() => {
        if (!sourceImg || cropMode) {
            return;
        }
        const rect = settings.crop ?? {
            x: 0,
            y: 0,
            width: sourceImg.naturalWidth,
            height: sourceImg.naturalHeight,
        };
        let revoked = false;
        cropToBlob(sourceImg, rect)
            .then((blob) => {
                if (revoked) {
                    return;
                }
                const url = URL.createObjectURL(blob);
                croppedUrlRef.current = url;
                setCroppedUrl((prev) => {
                    if (prev) {
                        URL.revokeObjectURL(prev);
                    }

                    return url;
                });
            })
            .catch(() => {
                if (!revoked) {
                    setLoadError(true);
                }
            });

        return () => {
            revoked = true;
        };
    }, [sourceImg, settings.crop, cropMode]);

    // Revoke the last-held croppedUrl when the editor unmounts (between-crops
    // revocation is already handled by the functional updater above).
    useEffect(
        () => () => {
            if (croppedUrlRef.current) {
                URL.revokeObjectURL(croppedUrlRef.current);
            }
        },
        [],
    );

    // Reset the focus latch when the dialog closes or the queue advances to a
    // new image (open stays true across multi-image batches).
    useEffect(() => {
        if (!open) {
            hasFocusedPrimaryRef.current = false;
        }
    }, [open]);

    useEffect(() => {
        hasFocusedPrimaryRef.current = false;
    }, [sourceUrl]);

    // The primary button is disabled until the cropped preview is ready, so Base
    // UI's open-time focus would land on the close X. Focus Upload/Apply once it
    // becomes clickable so Enter accepts the image.
    useEffect(() => {
        if (!open || hasFocusedPrimaryRef.current) {
            return;
        }
        if (isSaving || (!cropMode && !croppedUrl)) {
            return;
        }
        hasFocusedPrimaryRef.current = true;
        const id = requestAnimationFrame(() => {
            primaryButtonRef.current?.focus();
        });

        return () => cancelAnimationFrame(id);
    }, [open, croppedUrl, cropMode, isSaving]);

    const contentW = settings.crop?.width ?? sourceImg?.naturalWidth ?? 1;
    const contentH = settings.crop?.height ?? sourceImg?.naturalHeight ?? 1;
    // Zoom scales the (cropped) image within the frame; the background padding
    // stays fixed, so zooming out reveals more background and zooming in fills it.
    const renderW = Math.max(1, Math.round(contentW * settings.zoom));
    const renderH = Math.max(1, Math.round(contentH * settings.zoom));
    // The canvas always hugs the (scaled) image + padding; the aspect preset
    // drives the crop ratio, not a letterboxed background.
    const stage = stageDimensions(renderW, renderH, settings.padding, 'auto');
    const fits = box.w > 0 && box.h > 0;
    // Fit the (cropped) image inside the measured canvas, leaving a margin so the
    // dotted background always frames it and the bounds stay obvious.
    const previewScale = fits
        ? Math.min(box.w / stage.width, box.h / stage.height, 1) * 0.92
        : 1;

    // Picking an aspect crops the image to that ratio (centred); 'auto' clears it.
    function selectAspect(aspect: AspectPreset) {
        const ratio = aspectToRatio(aspect);
        setSettings((s) => ({
            ...s,
            aspect,
            crop:
                ratio !== null && sourceImg
                    ? centeredCropForRatio(
                          sourceImg.naturalWidth,
                          sourceImg.naturalHeight,
                          ratio,
                      )
                    : null,
        }));
    }

    async function apply() {
        const node = stageRef.current;
        if (!node || !croppedUrl) {
            return;
        }
        try {
            const composed = await rasterizeStage(
                node,
                Math.max(stage.width, stage.height),
            );
            await onApply(composed, settings, altText);
        } catch {
            // Upload errors are toasted by the hook; a rasterize failure lands
            // here — surface it rather than failing silently.
            toast.error('Couldn’t process that image. Please try again.');
        }
    }

    const hasQueue = queue !== undefined && queue.thumbnails.length > 1;
    // Whether the user actually changed anything. With no edits, applying would
    // just re-encode the original for no benefit — so the primary button instead
    // takes the "keep as-is" path and the redundant skip shortcut is dropped.
    const isEdited =
        altText !== (initialAltText ?? '') ||
        JSON.stringify(settings) !== JSON.stringify(initialSettings);
    const cancelLabel =
        variant === 'new' ? 'Continue without editing' : 'Cancel';
    // Closing via X / Escape discards a fresh upload, or just closes a re-edit.
    const closeAction = variant === 'new' ? onDiscard : onCancel;
    const primaryLabel = cropMode
        ? 'Done cropping'
        : isSaving
          ? 'Saving…'
          : isEdited
            ? hasQueue
                ? 'Apply & next'
                : 'Apply'
            : variant === 'new'
              ? hasQueue
                  ? 'Upload & next'
                  : 'Upload'
              : 'Done';
    // Unedited: onCancel already implements "keep as-is" (upload the original &
    // advance for a fresh batch, or just close a re-edit).
    const primaryAction = cropMode
        ? () => setCropMode(false)
        : isEdited
          ? apply
          : onCancel;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    closeAction();
                }
            }}
        >
            <DialogContent
                showCloseButton={false}
                initialFocus={primaryButtonRef}
                className="flex h-dvh w-full max-w-none flex-col gap-0 overflow-hidden rounded-none p-0 sm:h-[85vh] sm:max-h-[760px] sm:w-[min(1080px,95vw)] sm:max-w-none sm:rounded-[min(var(--radius-4xl),24px)]"
            >
                {/* Header — own the close button so it aligns with the title */}
                <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
                    <DialogTitle className="text-sm font-semibold">
                        Edit image
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Crop, set an aspect ratio, and optionally add a
                        background and effects before attaching the image.
                    </DialogDescription>
                    <div className="flex items-center gap-3">
                        {hasQueue && (
                            <span className="text-xs font-medium text-muted-foreground tabular-nums">
                                Image {queue.index + 1} of{' '}
                                {queue.thumbnails.length}
                            </span>
                        )}
                        <button
                            type="button"
                            aria-label="Close"
                            onClick={closeAction}
                            className="-mr-1 grid size-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                            <X className="size-4" aria-hidden="true" />
                        </button>
                    </div>
                </header>

                {/* Body: canvas + inspector rail (stacked on mobile, side-by-side on desktop) */}
                <div className="flex min-h-0 flex-1 flex-col md:flex-row">
                    {/* Canvas — bounded height on mobile so controls keep scrollable room */}
                    <section className="flex h-[45dvh] min-h-0 shrink-0 flex-col bg-muted/20 md:h-auto md:flex-1">
                        <div
                            ref={setPreviewEl}
                            className="relative grid flex-1 place-items-center overflow-hidden bg-[radial-gradient(var(--color-border)_1px,transparent_1px)] [background-size:16px_16px] p-5 sm:p-6"
                        >
                            {loadError ? (
                                <p className="text-sm text-muted-foreground">
                                    Couldn’t load that image. Remove it and try
                                    again.
                                </p>
                            ) : cropMode && sourceImg ? (
                                <CropOverlay
                                    imageSrc={sourceUrl ?? ''}
                                    sourceSize={{
                                        width: sourceImg.naturalWidth,
                                        height: sourceImg.naturalHeight,
                                    }}
                                    rect={
                                        settings.crop ?? {
                                            x: 0,
                                            y: 0,
                                            width: sourceImg.naturalWidth,
                                            height: sourceImg.naturalHeight,
                                        }
                                    }
                                    ratio={aspectToRatio(settings.aspect)}
                                    maxW={box.w || undefined}
                                    maxH={box.h || undefined}
                                    onChange={(crop) =>
                                        setSettings((s) => ({
                                            ...s,
                                            crop: clampCropRect(
                                                crop,
                                                sourceImg.naturalWidth,
                                                sourceImg.naturalHeight,
                                            ),
                                        }))
                                    }
                                />
                            ) : croppedUrl && fits ? (
                                // The stage is rendered at natural size, then absolutely
                                // centred and scaled to fit. Absolute positioning keeps its
                                // (large) layout box out of flow, so the overflow-hidden
                                // canvas always clips it — it can never escape the modal.
                                <div
                                    className="absolute top-1/2 left-1/2"
                                    style={{
                                        transform: `translate(-50%, -50%) scale(${previewScale})`,
                                    }}
                                >
                                    <ImageStage
                                        ref={stageRef}
                                        imageSrc={croppedUrl}
                                        settings={settings}
                                        contentSize={{
                                            width: renderW,
                                            height: renderH,
                                        }}
                                    />
                                </div>
                            ) : (
                                <div className="size-7 animate-spin rounded-full border-2 border-foreground/40 border-t-transparent" />
                            )}
                        </div>

                        {/* Multi-image filmstrip */}
                        {hasQueue && (
                            <div className="flex shrink-0 items-center gap-3 border-t border-border bg-popover px-4 py-2.5">
                                <div className="flex flex-1 gap-2 overflow-x-auto">
                                    {queue.thumbnails.map((src, i) => (
                                        <div
                                            key={src}
                                            aria-current={
                                                i === queue.index || undefined
                                            }
                                            className={cn(
                                                'size-10 shrink-0 overflow-hidden rounded-md border transition',
                                                i === queue.index
                                                    ? 'border-foreground ring-2 ring-foreground/25'
                                                    : 'border-border opacity-45',
                                            )}
                                        >
                                            <img
                                                src={src}
                                                alt=""
                                                draggable={false}
                                                className="size-full object-cover"
                                            />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>

                    {/* Inspector rail — fills remaining height and scrolls on mobile;
                        a fixed-width side panel on desktop */}
                    <aside className="flex min-h-0 w-full flex-1 flex-col gap-5 overflow-y-auto border-t border-border p-5 md:w-[288px] md:flex-none md:border-t-0 md:border-l">
                        <Field label="Aspect ratio">
                            <div className="grid grid-cols-3 gap-1 rounded-lg bg-muted/60 p-1">
                                {ASPECT_PRESETS.map((a) => (
                                    <Segment
                                        key={a}
                                        active={settings.aspect === a}
                                        onClick={() => selectAspect(a)}
                                    >
                                        {a === 'auto' ? 'Auto' : a}
                                    </Segment>
                                ))}
                            </div>
                        </Field>

                        {!cropMode && (
                            <button
                                type="button"
                                onClick={() => setCropMode(true)}
                                className="flex w-full items-center justify-center gap-2 rounded-lg border border-border py-2 text-sm font-medium transition-colors hover:bg-muted"
                            >
                                <Crop className="size-4" aria-hidden="true" />
                                Crop image
                            </button>
                        )}

                        <Field label="Alt text">
                            <textarea
                                value={altText}
                                onChange={(e) => setAltText(e.target.value)}
                                placeholder="Describe the image for accessibility"
                                rows={3}
                                maxLength={1000}
                                className="w-full resize-none rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:ring-2 focus:ring-foreground/20 focus:outline-none"
                            />
                            <p className="text-[11px] text-muted-foreground">
                                Describe the image for screen readers
                            </p>
                        </Field>

                        {/* Effects & background (opt-in) */}
                        <div className="border-t border-border pt-5">
                            <button
                                type="button"
                                aria-expanded={advanced}
                                onClick={() => setAdvanced((v) => !v)}
                                className="flex w-full items-center justify-between text-sm font-medium text-foreground"
                            >
                                <span className="flex items-center gap-2">
                                    <Wand2
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Effects &amp; background
                                </span>
                                <ChevronDown
                                    className={cn(
                                        'size-4 text-muted-foreground transition-transform',
                                        advanced && 'rotate-180',
                                    )}
                                    aria-hidden="true"
                                />
                            </button>

                            {advanced && (
                                <div className="mt-4 space-y-5">
                                    <Field label="Background">
                                        <div className="grid grid-cols-4 gap-2">
                                            <button
                                                type="button"
                                                aria-label="No background"
                                                aria-pressed={
                                                    settings.background.type ===
                                                    'none'
                                                }
                                                onClick={() =>
                                                    setSettings((s) => ({
                                                        ...s,
                                                        background:
                                                            NO_BACKGROUND,
                                                    }))
                                                }
                                                className={cn(
                                                    'relative h-10 overflow-hidden rounded-md bg-[linear-gradient(45deg,var(--color-muted)_25%,transparent_25%,transparent_75%,var(--color-muted)_75%),linear-gradient(45deg,var(--color-muted)_25%,transparent_25%,transparent_75%,var(--color-muted)_75%)] bg-[length:14px_14px] bg-[position:0_0,7px_7px] ring-offset-2 ring-offset-popover transition md:h-8',
                                                    settings.background.type ===
                                                        'none'
                                                        ? 'ring-2 ring-foreground'
                                                        : 'hover:scale-105',
                                                )}
                                            >
                                                <span
                                                    className="absolute top-1/2 left-1/2 h-0.5 w-12 -translate-x-1/2 -translate-y-1/2 -rotate-45 rounded-full bg-destructive"
                                                    aria-hidden="true"
                                                />
                                            </button>

                                            {GRADIENTS.map((g) => (
                                                <button
                                                    key={g.id}
                                                    type="button"
                                                    aria-label={g.name}
                                                    aria-pressed={
                                                        settings.background
                                                            .id === g.id
                                                    }
                                                    onClick={() =>
                                                        setSettings((s) => {
                                                            const next = {
                                                                ...s,
                                                                background:
                                                                    gradientToFill(
                                                                        g,
                                                                    ),
                                                            };
                                                            // The first time a background is chosen it's
                                                            // invisible without framing, so apply a sensible
                                                            // padding + corner radius + shadow once. Never
                                                            // re-impose them afterwards — the user may dial
                                                            // any of them back deliberately.
                                                            if (
                                                                !hasAutoStyledRef.current
                                                            ) {
                                                                next.padding = 96;
                                                                next.radius = 16;
                                                                next.shadow =
                                                                    'medium';
                                                            }
                                                            hasAutoStyledRef.current = true;

                                                            return next;
                                                        })
                                                    }
                                                    className={cn(
                                                        'h-10 rounded-md ring-offset-2 ring-offset-popover transition md:h-8',
                                                        settings.background
                                                            .id === g.id
                                                            ? 'ring-2 ring-foreground'
                                                            : 'hover:scale-105',
                                                    )}
                                                    style={{
                                                        background: `linear-gradient(${g.angle}deg, ${g.stops[0].color}, ${g.stops[g.stops.length - 1].color})`,
                                                    }}
                                                />
                                            ))}
                                        </div>
                                    </Field>

                                    <Slider
                                        label="Padding"
                                        min={0}
                                        max={200}
                                        value={settings.padding}
                                        onChange={(padding) =>
                                            setSettings((s) => ({
                                                ...s,
                                                padding,
                                            }))
                                        }
                                    />
                                    <Slider
                                        label="Zoom"
                                        min={ZOOM_MIN * 100}
                                        max={ZOOM_MAX * 100}
                                        suffix="%"
                                        value={settings.zoom * 100}
                                        onChange={(percent) =>
                                            setSettings((s) => ({
                                                ...s,
                                                zoom: percent / 100,
                                            }))
                                        }
                                    />
                                    <Slider
                                        label="Corner radius"
                                        min={0}
                                        max={64}
                                        value={settings.radius}
                                        onChange={(radius) =>
                                            setSettings((s) => ({
                                                ...s,
                                                radius,
                                            }))
                                        }
                                    />
                                    <Slider
                                        label="Tilt X"
                                        min={-30}
                                        max={30}
                                        suffix="°"
                                        value={settings.tilt.rotateX}
                                        onChange={(rotateX) =>
                                            setSettings((s) => ({
                                                ...s,
                                                tilt: { ...s.tilt, rotateX },
                                            }))
                                        }
                                    />
                                    <Slider
                                        label="Tilt Y"
                                        min={-30}
                                        max={30}
                                        suffix="°"
                                        value={settings.tilt.rotateY}
                                        onChange={(rotateY) =>
                                            setSettings((s) => ({
                                                ...s,
                                                tilt: { ...s.tilt, rotateY },
                                            }))
                                        }
                                    />

                                    <Field label="Shadow">
                                        <div className="grid grid-cols-4 gap-1 rounded-lg bg-muted/60 p-1">
                                            {SHADOW_PRESETS.map((sh) => (
                                                <Segment
                                                    key={sh}
                                                    active={
                                                        settings.shadow === sh
                                                    }
                                                    onClick={() =>
                                                        setSettings((s) => ({
                                                            ...s,
                                                            shadow: sh,
                                                        }))
                                                    }
                                                >
                                                    <span className="capitalize">
                                                        {sh}
                                                    </span>
                                                </Segment>
                                            ))}
                                        </div>
                                    </Field>
                                </div>
                            )}
                        </div>
                    </aside>
                </div>

                {/* Footer — Remove (discard) on the left; keep/apply on the right */}
                <footer className="flex shrink-0 items-center gap-2 border-t border-border px-4 py-3 sm:px-5">
                    <button
                        type="button"
                        onClick={onDiscard}
                        className="flex items-center gap-1.5 rounded-md px-2.5 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive md:py-2"
                    >
                        <Trash2 className="size-4" aria-hidden="true" />
                        Remove
                    </button>
                    <div className="ml-auto flex items-center gap-2">
                        {isEdited && (
                            <button
                                type="button"
                                className="rounded-md px-3 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground md:py-2"
                                onClick={onCancel}
                            >
                                {cancelLabel}
                            </button>
                        )}
                        <button
                            ref={primaryButtonRef}
                            type="button"
                            disabled={isSaving || (!cropMode && !croppedUrl)}
                            onClick={primaryAction}
                            className="rounded-md bg-foreground px-4 py-2.5 text-sm font-medium text-background transition-opacity hover:opacity-90 disabled:opacity-50 md:py-2"
                        >
                            {primaryLabel}
                        </button>
                    </div>
                </footer>
            </DialogContent>
        </Dialog>
    );
}

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
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'rounded-md py-2 text-center text-sm font-medium transition-colors md:py-1.5 md:text-xs',
                active
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}

function Slider({
    label,
    min,
    max,
    value,
    suffix = '',
    onChange,
}: {
    label: string;
    min: number;
    max: number;
    value: number;
    suffix?: string;
    onChange: (value: number) => void;
}) {
    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-foreground/80">
                    {label}
                </span>
                <span className="text-xs text-muted-foreground tabular-nums">
                    {Math.round(value)}
                    {suffix}
                </span>
            </div>
            <input
                type="range"
                min={min}
                max={max}
                value={value}
                aria-label={label}
                onChange={(e) => onChange(Number(e.target.value))}
                className="w-full accent-foreground"
            />
        </div>
    );
}
