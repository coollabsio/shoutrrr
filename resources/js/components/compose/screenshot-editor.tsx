import { useEffect, useRef, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { useScreenshot } from '@/hooks/compose/use-screenshot';
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
    defaultSettings,
    type EditSettings,
    SHADOW_PRESETS,
} from '@/lib/screenshot/settings';
import { cn } from '@/lib/utils';

import { CropOverlay } from './crop-overlay';
import { ScreenshotStage } from './screenshot-stage';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sourceFile?: File | null;
    editTarget?: {
        mediaId: string;
        sourceUrl: string;
        settings: EditSettings;
    } | null;
    screenshot: ReturnType<typeof useScreenshot>;
};

const PREVIEW_MAX = 460;

export function ScreenshotEditor({
    open,
    onOpenChange,
    sourceFile,
    editTarget,
    screenshot,
}: Props) {
    const stageRef = useRef<HTMLDivElement | null>(null);
    const [settings, setSettings] = useState<EditSettings>(defaultSettings);
    const [sourceUrl, setSourceUrl] = useState<string | null>(null);
    const [sourceImg, setSourceImg] = useState<HTMLImageElement | null>(null);
    // The cropped image as an object-URL fed to the stage; null until prepared.
    const [croppedUrl, setCroppedUrl] = useState<string | null>(null);
    const [cropMode, setCropMode] = useState(false);

    // Load the source (fresh file or re-edit URL) whenever the editor opens.
    useEffect(() => {
        if (!open) {
            return;
        }
        const url = sourceFile
            ? URL.createObjectURL(sourceFile)
            : (editTarget?.sourceUrl ?? null);
        setSourceUrl(url);
        setSettings(editTarget?.settings ?? defaultSettings());
        if (!url) {
            return;
        }
        let revoked = false;
        void loadImage(url).then((img) => {
            if (!revoked) {
                setSourceImg(img);
            }
        });

        return () => {
            revoked = true;
            if (sourceFile && url) {
                URL.revokeObjectURL(url);
            }
        };
    }, [open, sourceFile, editTarget]);

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

    if (!sourceImg || !sourceUrl) {
        // Still loading; render the shell so the dialog can animate in.
    }

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
            const sourceBlob = await fetch(sourceUrl!).then((r) => r.blob());
            if (editTarget) {
                await screenshot.applyEdit(
                    editTarget.mediaId,
                    composed,
                    settings,
                );
            } else {
                await screenshot.applyNew(composed, sourceBlob, settings);
            }
            onOpenChange(false);
        } catch {
            // toast handled in the hook / rasterizeStage throw surfaces below
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Beautify screenshot</DialogTitle>
                </DialogHeader>

                <div className="grid gap-6 md:grid-cols-[1fr_220px]">
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
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={screenshot.isSaving || !croppedUrl}
                        onClick={apply}
                        className="rounded-md bg-foreground px-3 py-1.5 text-sm font-medium text-background disabled:opacity-50"
                    >
                        {screenshot.isSaving ? 'Saving…' : 'Apply'}
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
