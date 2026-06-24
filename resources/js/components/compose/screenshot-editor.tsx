import { useEffect, useRef, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cropToBlob, loadImage } from '@/lib/screenshot/crop';
import { rasterizeStage } from '@/lib/screenshot/export';
import { GRADIENTS, gradientToFill } from '@/lib/screenshot/gradients';
import {
    aspectToRatio,
    clampCropRect,
    stageDimensions,
} from '@/lib/screenshot/layout';
import {
    ASPECT_PRESETS,
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
};

const PREVIEW_MAX = 460;

export function ScreenshotEditor({
    open,
    sourceUrl,
    initialSettings,
    onApply,
    onCancel,
    isSaving,
}: Props) {
    const stageRef = useRef<HTMLDivElement | null>(null);
    const croppedUrlRef = useRef<string | null>(null);
    const [settings, setSettings] = useState<EditSettings>(initialSettings);
    const [sourceImg, setSourceImg] = useState<HTMLImageElement | null>(null);
    // The cropped image as an object-URL fed to the stage; null until prepared.
    const [croppedUrl, setCroppedUrl] = useState<string | null>(null);
    const [cropMode, setCropMode] = useState(false);

    // (Re)load the source and reset settings whenever the edited image changes
    // — covers both a fresh open and advancing to the next queued image.
    useEffect(() => {
        if (!sourceUrl) {
            return;
        }
        setSettings(initialSettings);
        setSourceImg(null);
        setCropMode(false);
        let revoked = false;
        void loadImage(sourceUrl).then((img) => {
            if (!revoked) {
                setSourceImg(img);
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
        void cropToBlob(sourceImg, rect).then((blob) => {
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
    const stage = stageDimensions(
        contentW,
        contentH,
        settings.padding,
        settings.aspect,
    );
    const previewScale = Math.min(
        1,
        PREVIEW_MAX / Math.max(stage.width, stage.height),
    );

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

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onCancel();
                }
            }}
        >
            <DialogContent className="flex max-h-[90vh] max-w-3xl flex-col overflow-hidden">
                <DialogHeader>
                    <DialogTitle>Edit image</DialogTitle>
                </DialogHeader>

                <div className="grid flex-1 gap-6 overflow-y-auto md:grid-cols-[1fr_220px]">
                    {/* Preview / crop */}
                    <div className="grid min-h-[300px] place-items-center overflow-hidden rounded-lg bg-muted/40 p-4">
                        {cropMode && sourceImg ? (
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
                            <div
                                style={{
                                    transform: `scale(${previewScale})`,
                                    transformOrigin: 'center',
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
                        ) : (
                            <div className="size-8 animate-spin rounded-full border-2 border-foreground/60 border-t-transparent" />
                        )}
                    </div>

                    {/* Controls */}
                    <div className="space-y-4 text-sm">
                        <Control label="Background">
                            <div className="grid grid-cols-4 gap-1.5">
                                {GRADIENTS.map((g) => (
                                    <button
                                        key={g.id}
                                        type="button"
                                        aria-label={g.name}
                                        onClick={() =>
                                            setSettings((s) => ({
                                                ...s,
                                                background: gradientToFill(g),
                                            }))
                                        }
                                        className={cn(
                                            'h-7 rounded-md ring-offset-2 ring-offset-background',
                                            settings.background.id === g.id &&
                                                'ring-2 ring-foreground',
                                        )}
                                        style={{
                                            background: `linear-gradient(${g.angle}deg, ${g.stops[0].color}, ${g.stops[g.stops.length - 1].color})`,
                                        }}
                                    />
                                ))}
                            </div>
                        </Control>

                        <RangeControl
                            label="Padding"
                            min={0}
                            max={200}
                            value={settings.padding}
                            onChange={(padding) =>
                                setSettings((s) => ({ ...s, padding }))
                            }
                        />
                        <RangeControl
                            label="Corner radius"
                            min={0}
                            max={64}
                            value={settings.radius}
                            onChange={(radius) =>
                                setSettings((s) => ({ ...s, radius }))
                            }
                        />
                        <RangeControl
                            label="Tilt X"
                            min={-30}
                            max={30}
                            value={settings.tilt.rotateX}
                            onChange={(rotateX) =>
                                setSettings((s) => ({
                                    ...s,
                                    tilt: { ...s.tilt, rotateX },
                                }))
                            }
                        />
                        <RangeControl
                            label="Tilt Y"
                            min={-30}
                            max={30}
                            value={settings.tilt.rotateY}
                            onChange={(rotateY) =>
                                setSettings((s) => ({
                                    ...s,
                                    tilt: { ...s.tilt, rotateY },
                                }))
                            }
                        />

                        <Control label="Shadow">
                            <div className="flex gap-1">
                                {SHADOW_PRESETS.map((sh) => (
                                    <button
                                        key={sh}
                                        type="button"
                                        onClick={() =>
                                            setSettings((s) => ({
                                                ...s,
                                                shadow: sh,
                                            }))
                                        }
                                        className={cn(
                                            'flex-1 rounded-md border border-border py-1 text-xs capitalize',
                                            settings.shadow === sh &&
                                                'bg-foreground text-background',
                                        )}
                                    >
                                        {sh}
                                    </button>
                                ))}
                            </div>
                        </Control>

                        <Control label="Aspect">
                            <div className="grid grid-cols-3 gap-1">
                                {ASPECT_PRESETS.map((a) => (
                                    <button
                                        key={a}
                                        type="button"
                                        onClick={() =>
                                            setSettings((s) => ({
                                                ...s,
                                                aspect: a,
                                            }))
                                        }
                                        className={cn(
                                            'rounded-md border border-border py-1 text-xs',
                                            settings.aspect === a &&
                                                'bg-foreground text-background',
                                        )}
                                    >
                                        {a}
                                    </button>
                                ))}
                            </div>
                        </Control>

                        <button
                            type="button"
                            onClick={() => setCropMode((v) => !v)}
                            className={cn(
                                'w-full rounded-md border border-border py-1.5 text-xs',
                                cropMode && 'bg-foreground text-background',
                            )}
                        >
                            {cropMode ? 'Done cropping' : 'Crop'}
                        </button>
                    </div>
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        className="rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground"
                        onClick={onCancel}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={isSaving || !croppedUrl}
                        onClick={apply}
                        className="rounded-md bg-foreground px-3 py-1.5 text-sm font-medium text-background disabled:opacity-50"
                    >
                        {isSaving ? 'Saving…' : 'Apply'}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Control({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <div className="text-xs font-medium text-muted-foreground">
                {label}
            </div>
            {children}
        </div>
    );
}

function RangeControl({
    label,
    min,
    max,
    value,
    onChange,
}: {
    label: string;
    min: number;
    max: number;
    value: number;
    onChange: (value: number) => void;
}) {
    return (
        <Control label={`${label} (${Math.round(value)})`}>
            <input
                type="range"
                min={min}
                max={max}
                value={value}
                onChange={(e) => onChange(Number(e.target.value))}
                className="w-full accent-foreground"
            />
        </Control>
    );
}
