import { ChevronDown, Crop, Wand2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cropToBlob, loadImage } from '@/lib/screenshot/crop';
import { rasterizeStage } from '@/lib/screenshot/export';
import { GRADIENTS, gradientToFill } from '@/lib/screenshot/gradients';
import {
    aspectToRatio,
    centeredCropForRatio,
    clampCropRect,
    stageDimensions,
} from '@/lib/screenshot/layout';
import {
    ASPECT_PRESETS,
    type AspectPreset,
    type EditSettings,
    SHADOW_PRESETS,
} from '@/lib/screenshot/settings';
import { cn } from '@/lib/utils';

import { CropOverlay } from './crop-overlay';
import { ScreenshotStage } from './screenshot-stage';

type Props = {
    open: boolean;
    /** Source image to edit — an object URL or same-origin URL. The PARENT owns its lifecycle. */
    sourceUrl: string | null;
    /** Initial settings: defaults for a fresh image, persisted settings on re-edit. */
    initialSettings: EditSettings;
    /**
     * Compose + persist the result. The parent decides what to upload (new vs
     * replace) and advances the queue / closes the modal afterwards.
     */
    onApply: (composed: Blob, settings: EditSettings) => Promise<void> | void;
    /** Dismiss without applying (X / Escape / Cancel). The parent decides the fallback. */
    onCancel: () => void;
    /** True while an upload triggered by onApply is in flight. */
    isSaving: boolean;
    /** Thumbnails of a multi-image batch + the index being edited, shown as a strip. */
    queue?: { thumbnails: string[]; index: number };
};

export function ScreenshotEditor({
    open,
    sourceUrl,
    initialSettings,
    onApply,
    onCancel,
    isSaving,
    queue,
}: Props) {
    const stageRef = useRef<HTMLDivElement | null>(null);
    const croppedUrlRef = useRef<string | null>(null);
    const previewBoxRef = useRef<HTMLDivElement | null>(null);
    const [settings, setSettings] = useState<EditSettings>(initialSettings);
    const [sourceImg, setSourceImg] = useState<HTMLImageElement | null>(null);
    // The cropped image as an object-URL fed to the stage; null until prepared.
    const [croppedUrl, setCroppedUrl] = useState<string | null>(null);
    const [cropMode, setCropMode] = useState(false);
    const [advanced, setAdvanced] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [box, setBox] = useState<{ w: number; h: number }>({ w: 0, h: 0 });

    // Measure the preview area so the image scales to fill it at any modal size.
    useEffect(() => {
        const el = previewBoxRef.current;
        if (!el) {
            return;
        }
        const ro = new ResizeObserver((entries) => {
            const rect = entries[0]?.contentRect;
            if (rect) {
                setBox({ w: rect.width, h: rect.height });
            }
        });
        ro.observe(el);

        return () => ro.disconnect();
    }, [open]);

    // (Re)load the source and reset settings whenever the edited image changes
    // — covers both a fresh open and advancing to the next queued image.
    useEffect(() => {
        if (!sourceUrl) {
            return;
        }
        setSettings(initialSettings);
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
    }, [sourceUrl, initialSettings]);

    // Recompute the cropped image whenever the source or crop rect changes.
    useEffect(() => {
        if (!sourceImg) {
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
    }, [sourceImg, settings.crop]);

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

    const contentW = settings.crop?.width ?? sourceImg?.naturalWidth ?? 1;
    const contentH = settings.crop?.height ?? sourceImg?.naturalHeight ?? 1;
    // The canvas always hugs the (cropped) image + padding; the aspect preset
    // drives the crop ratio, not a letterboxed background.
    const stage = stageDimensions(contentW, contentH, settings.padding, 'auto');
    const previewScale =
        box.w > 0 && box.h > 0
            ? Math.min(box.w / stage.width, box.h / stage.height, 1)
            : Math.min(1, 460 / Math.max(stage.width, stage.height));

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
            await onApply(composed, settings);
        } catch {
            // upload errors are toasted by the hook; a rasterize throw lands here
        }
    }

    const hasQueue = queue !== undefined && queue.thumbnails.length > 1;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onCancel();
                }
            }}
        >
            <DialogContent className="flex h-[85vh] max-h-[760px] w-[min(1080px,95vw)] max-w-none flex-col gap-0 overflow-hidden p-0 sm:max-w-none">
                {/* Header */}
                <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border px-5 py-3 pr-12">
                    <DialogTitle className="text-sm font-semibold">
                        Edit image
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Crop, set an aspect ratio, and optionally add a
                        background and effects before attaching the image.
                    </DialogDescription>
                    {hasQueue && (
                        <span className="text-xs font-medium text-muted-foreground tabular-nums">
                            Image {queue.index + 1} of {queue.thumbnails.length}
                        </span>
                    )}
                </header>

                {/* Body: canvas + inspector rail */}
                <div className="flex min-h-0 flex-1 flex-col md:flex-row">
                    {/* Canvas */}
                    <section className="flex min-h-0 flex-1 flex-col bg-muted/20">
                        <div
                            ref={previewBoxRef}
                            className="grid flex-1 place-items-center overflow-hidden bg-[radial-gradient(var(--color-border)_1px,transparent_1px)] [background-size:16px_16px] p-6"
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
                            ) : croppedUrl ? (
                                // Outer box takes the SCALED footprint so it centres
                                // cleanly; the inner stage renders at natural size and
                                // scales from the top-left to fill that footprint.
                                <div
                                    style={{
                                        width: Math.round(
                                            stage.width * previewScale,
                                        ),
                                        height: Math.round(
                                            stage.height * previewScale,
                                        ),
                                    }}
                                >
                                    <div
                                        style={{
                                            width: stage.width,
                                            height: stage.height,
                                            transform: `scale(${previewScale})`,
                                            transformOrigin: 'top left',
                                        }}
                                    >
                                        <ScreenshotStage
                                            ref={stageRef}
                                            imageSrc={croppedUrl}
                                            settings={settings}
                                            contentSize={{
                                                width: contentW,
                                                height: contentH,
                                            }}
                                        />
                                    </div>
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

                    {/* Inspector rail */}
                    <aside className="flex w-full shrink-0 flex-col gap-5 overflow-y-auto border-t border-border p-5 md:w-[288px] md:border-t-0 md:border-l">
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

                        <button
                            type="button"
                            onClick={() => setCropMode((v) => !v)}
                            className={cn(
                                'flex w-full items-center justify-center gap-2 rounded-lg border py-2 text-sm font-medium transition-colors',
                                cropMode
                                    ? 'border-foreground bg-foreground text-background'
                                    : 'border-border hover:bg-muted',
                            )}
                        >
                            <Crop className="size-4" aria-hidden="true" />
                            {cropMode ? 'Done cropping' : 'Crop image'}
                        </button>

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
                                                        setSettings((s) => ({
                                                            ...s,
                                                            background:
                                                                gradientToFill(
                                                                    g,
                                                                ),
                                                        }))
                                                    }
                                                    className={cn(
                                                        'h-8 rounded-md ring-offset-2 ring-offset-popover transition',
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

                {/* Footer */}
                <footer className="flex shrink-0 items-center justify-end gap-2 border-t border-border px-5 py-3">
                    <button
                        type="button"
                        className="rounded-md px-3.5 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        onClick={onCancel}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={isSaving || !croppedUrl}
                        onClick={apply}
                        className="rounded-md bg-foreground px-4 py-2 text-sm font-medium text-background transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {isSaving
                            ? 'Saving…'
                            : hasQueue
                              ? 'Apply & next'
                              : 'Apply'}
                    </button>
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
                'rounded-md py-1.5 text-center text-xs font-medium transition-colors',
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
