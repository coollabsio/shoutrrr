import {
    encodeCanvasToFit,
    MAX_COMPOSED_BYTES,
} from '@/lib/image-editor/export';

/** The server's upload byte cap (StorePostMediaRequest `max:8192` KiB). */
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

/**
 * Largest width/height with the same aspect ratio whose pixel count fits
 * `maxPixels`. Returns the input unchanged when it already fits.
 */
export function fitWithinPixels(
    width: number,
    height: number,
    maxPixels: number,
): { width: number; height: number } {
    if (width * height <= maxPixels) {
        return { width, height };
    }
    const scale = Math.sqrt(maxPixels / (width * height));

    return {
        width: Math.max(1, Math.floor(width * scale)),
        height: Math.max(1, Math.floor(height * scale)),
    };
}

/**
 * Downscale and re-encode an image the server would reject for its resolution
 * or byte size (#189). Returns the original file when it already fits, is a GIF
 * (re-encoding drops the animation), or the browser cannot process it, so the
 * server still gives its own error.
 */
export async function fitImageForUpload(
    file: File,
    maxPixels: number,
): Promise<File> {
    if (file.type === 'image/gif' || typeof createImageBitmap !== 'function') {
        return file;
    }

    let bitmap: ImageBitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return file;
    }

    try {
        const target = fitWithinPixels(bitmap.width, bitmap.height, maxPixels);
        const fitsPixels =
            target.width === bitmap.width && target.height === bitmap.height;
        if (fitsPixels && file.size <= MAX_UPLOAD_BYTES) {
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = target.width;
        canvas.height = target.height;
        canvas
            .getContext('2d')
            ?.drawImage(bitmap, 0, 0, target.width, target.height);

        // JPEG stays JPEG; PNG/WebP go to WebP to keep any transparency.
        const type = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
        const blob = await encodeCanvasToFit(
            canvas,
            { type, quality: 0.92 },
            MAX_COMPOSED_BYTES,
        );
        const extension = blob.type.split('/')[1] ?? 'jpeg';
        const name = file.name.replace(/\.[^.]+$/, '') || 'image';

        return new File([blob], `${name}.${extension}`, { type: blob.type });
    } catch {
        return file;
    } finally {
        bitmap.close();
    }
}
