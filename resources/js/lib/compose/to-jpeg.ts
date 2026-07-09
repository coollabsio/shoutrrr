/**
 * Instagram accepts JPEG images only and publishes by URL, so when Instagram is
 * a selected destination we convert a non-JPEG upload to JPEG in the browser and
 * upload that instead. It's a single file shared by every destination — simple,
 * no server or database change — so co-posted platforms also get the JPEG.
 */

/** Non-JPEG raster types we can rasterize to JPEG in a canvas. */
const CONVERTIBLE = ['image/png', 'image/webp'];

/** Whether this upload should be converted to JPEG before sending. */
export function needsJpegConversion(file: File): boolean {
    return CONVERTIBLE.includes(file.type);
}

/**
 * Render the image onto a white canvas (JPEG has no alpha) and export it as JPEG.
 * Returns null if the browser can't decode/encode it, so callers can fall back to
 * uploading the original rather than blocking the upload.
 */
export async function convertToJpeg(file: File): Promise<File | null> {
    try {
        const bitmap = await createImageBitmap(file);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            bitmap.close?.();

            return null;
        }

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0);
        bitmap.close?.();

        const blob = await new Promise<Blob | null>((resolve) => {
            canvas.toBlob((b) => resolve(b), 'image/jpeg', 0.9);
        });

        if (!blob) {
            return null;
        }

        const name = `${file.name.replace(/\.\w+$/, '')}.jpg`;

        return new File([blob], name, { type: 'image/jpeg' });
    } catch {
        return null;
    }
}
