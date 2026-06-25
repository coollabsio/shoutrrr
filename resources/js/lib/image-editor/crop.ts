import type { CropRect } from './settings';

function decodeImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Could not load the image.'));
        img.src = src;
    });
}

/**
 * Load an image for cropping/export. Blob, data and object URLs are already
 * same-origin and load directly. A stored image (public disk, CDN or S3) is
 * fetched into a blob and loaded via an object URL instead of setting
 * `img.crossOrigin = 'anonymous'`: the latter fails outright when the storage
 * origin omits CORS headers (or serves a response cached without them), which
 * would otherwise make re-editing an attached image impossible. Loading from an
 * object URL keeps the drawn canvas untainted so `cropToBlob` can still export.
 */
export async function loadImage(src: string): Promise<HTMLImageElement> {
    if (src.startsWith('blob:') || src.startsWith('data:')) {
        return decodeImage(src);
    }
    const response = await fetch(src);
    if (!response.ok) {
        throw new Error('Could not load the image.');
    }
    const objectUrl = URL.createObjectURL(await response.blob());
    try {
        return await decodeImage(objectUrl);
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
}

/** Crop a region of the source image to a PNG blob via an offscreen canvas. */
export function cropToBlob(
    source: CanvasImageSource & { width: number; height: number },
    rect: CropRect,
): Promise<Blob> {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(rect.width));
    canvas.height = Math.max(1, Math.round(rect.height));
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return Promise.reject(new Error('Canvas 2D is unavailable.'));
    }
    ctx.drawImage(
        source,
        rect.x,
        rect.y,
        rect.width,
        rect.height,
        0,
        0,
        canvas.width,
        canvas.height,
    );

    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob) {
                resolve(blob);
            } else {
                reject(new Error('Could not crop the image.'));
            }
        }, 'image/png');
    });
}
