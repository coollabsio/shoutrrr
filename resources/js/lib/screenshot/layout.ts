import type { AspectPreset, CropRect } from './settings';

export type Size = { width: number; height: number };
export type Corner = 'nw' | 'ne' | 'se' | 'sw';

const RATIOS: Record<Exclude<AspectPreset, 'auto'>, number> = {
    '1:1': 1,
    '4:3': 4 / 3,
    '3:4': 3 / 4,
    '16:9': 16 / 9,
    '9:16': 9 / 16,
};

export function aspectToRatio(aspect: AspectPreset): number | null {
    return aspect === 'auto' ? null : RATIOS[aspect];
}

/**
 * The exported background rectangle. The content (cropped image) is padded on
 * all sides; for a fixed ratio the stage grows along whichever axis is binding
 * so the padded content always fits and the stage has exactly the target ratio.
 */
export function stageDimensions(
    contentW: number,
    contentH: number,
    padding: number,
    aspect: AspectPreset,
): Size {
    const innerW = contentW + padding * 2;
    const innerH = contentH + padding * 2;
    const ratio = aspectToRatio(aspect);
    if (ratio === null) {
        return { width: innerW, height: innerH };
    }
    if (innerW / innerH > ratio) {
        return { width: innerW, height: innerW / ratio };
    }

    return { width: innerH * ratio, height: innerH };
}

export function clampCropRect(
    rect: CropRect,
    boundsW: number,
    boundsH: number,
): CropRect {
    const width = Math.max(1, Math.min(rect.width, boundsW));
    const height = Math.max(1, Math.min(rect.height, boundsH));
    const x = Math.max(0, Math.min(rect.x, boundsW - width));
    const y = Math.max(0, Math.min(rect.y, boundsH - height));

    return { x, y, width, height };
}

const OPPOSITE: Record<Corner, Corner> = {
    nw: 'se',
    ne: 'sw',
    se: 'nw',
    sw: 'ne',
};

function cornerPoint(rect: CropRect, corner: Corner): { x: number; y: number } {
    const right = rect.x + rect.width;
    const bottom = rect.y + rect.height;
    switch (corner) {
        case 'nw':
            return { x: rect.x, y: rect.y };
        case 'ne':
            return { x: right, y: rect.y };
        case 'se':
            return { x: right, y: bottom };
        case 'sw':
            return { x: rect.x, y: bottom };
    }
}

export function resizeCorner(
    rect: CropRect,
    corner: Corner,
    dx: number,
    dy: number,
    ratio: number | null,
    boundsW: number,
    boundsH: number,
): CropRect {
    const anchor = cornerPoint(rect, OPPOSITE[corner]);
    const moving = cornerPoint(rect, corner);
    const mx = moving.x + dx;
    const my = moving.y + dy;

    let width = Math.max(1, Math.abs(mx - anchor.x));
    let height = Math.max(1, Math.abs(my - anchor.y));
    if (ratio !== null) {
        if (width / height > ratio) {
            height = width / ratio;
        } else {
            width = height * ratio;
        }
    }

    const signX = Math.sign(moving.x - anchor.x) || 1;
    const signY = Math.sign(moving.y - anchor.y) || 1;
    const x = signX > 0 ? anchor.x : anchor.x - width;
    const y = signY > 0 ? anchor.y : anchor.y - height;

    return clampCropRect({ x, y, width, height }, boundsW, boundsH);
}

export function moveCropRect(
    rect: CropRect,
    dx: number,
    dy: number,
    boundsW: number,
    boundsH: number,
): CropRect {
    return clampCropRect(
        { ...rect, x: rect.x + dx, y: rect.y + dy },
        boundsW,
        boundsH,
    );
}

/** The largest rect of the given ratio (w/h) centered within the source. */
export function centeredCropForRatio(
    sourceW: number,
    sourceH: number,
    ratio: number,
): CropRect {
    let width = sourceW;
    let height = width / ratio;
    if (height > sourceH) {
        height = sourceH;
        width = height * ratio;
    }

    return {
        x: (sourceW - width) / 2,
        y: (sourceH - height) / 2,
        width,
        height,
    };
}
