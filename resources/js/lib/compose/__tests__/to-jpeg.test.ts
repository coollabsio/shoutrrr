import { describe, expect, it } from 'vitest';

import { convertToJpeg, needsJpegConversion } from '@/lib/compose/to-jpeg';

function file(type: string, name = 'x'): File {
    return new File(['bytes'], name, { type });
}

describe('needsJpegConversion', () => {
    it('flags png and webp for conversion', () => {
        expect(needsJpegConversion(file('image/png'))).toBe(true);
        expect(needsJpegConversion(file('image/webp'))).toBe(true);
    });

    it('leaves jpeg and gif alone', () => {
        expect(needsJpegConversion(file('image/jpeg'))).toBe(false);
        expect(needsJpegConversion(file('image/gif'))).toBe(false);
    });
});

describe('convertToJpeg', () => {
    it('resolves to null when the browser cannot decode (no canvas)', async () => {
        // Best-effort contract: a failed encode never throws, so the caller falls
        // back to uploading the original file instead of blocking the upload.
        await expect(convertToJpeg(file('image/png'))).resolves.toBeNull();
    });
});
