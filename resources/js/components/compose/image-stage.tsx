import { forwardRef } from 'react';

import { backgroundCss } from '@/lib/image-editor/gradients';
import type { EditSettings, ShadowPreset } from '@/lib/image-editor/settings';

export const SHADOW_CSS: Record<ShadowPreset, string> = {
    none: 'none',
    soft: '0 8px 24px rgba(0,0,0,0.18)',
    medium: '0 18px 48px rgba(0,0,0,0.28)',
    strong: '0 32px 80px rgba(0,0,0,0.40)',
};

type Props = {
    imageSrc: string;
    settings: EditSettings;
    /** The cropped image's intrinsic pixel size (drives the stage element size). */
    contentSize: { width: number; height: number };
};

/**
 * The exported composition: a gradient background that pads a 3D-tilted,
 * rounded, shadowed image. Rendered at natural pixel size; callers scale
 * it down for the preview via a CSS transform. The forwarded ref is the node
 * html-to-image rasterizes.
 */
export const ImageStage = forwardRef<HTMLDivElement, Props>(function ImageStage(
    { imageSrc, settings, contentSize },
    ref,
) {
    const { padding, radius, shadow, tilt, background } = settings;

    return (
        <div
            ref={ref}
            style={{
                boxSizing: 'border-box',
                padding,
                width: 'max-content',
                background: backgroundCss(background),
                display: 'grid',
                placeItems: 'center',
                overflow: 'hidden',
                perspective: '1200px',
            }}
        >
            <img
                src={imageSrc}
                alt=""
                width={contentSize.width}
                height={contentSize.height}
                draggable={false}
                style={{
                    display: 'block',
                    width: contentSize.width,
                    height: contentSize.height,
                    borderRadius: radius,
                    boxShadow: SHADOW_CSS[shadow],
                    transform: `rotateX(${tilt.rotateX}deg) rotateY(${tilt.rotateY}deg)`,
                    transformStyle: 'preserve-3d',
                }}
            />
        </div>
    );
});
