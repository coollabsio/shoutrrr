// @vitest-environment jsdom
// The downscale step calls document.createElement('canvas'), so this suite needs a DOM.
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    computeExportScale,
    encodeCanvasToFit,
    pickComposedFormat,
} from '../export';
import { gradientToFill, GRADIENTS, NO_BACKGROUND } from '../gradients';
import { defaultSettings } from '../settings';

/** Encoded byte size for a fake canvas of `w`×`h` at `type`/`quality`. */
type SizeModel = (
    w: number,
    h: number,
    type: string,
    quality: number,
) => number | null;

/**
 * A canvas stand-in whose `toBlob` returns a controllable size — jsdom has no
 * real canvas encoder, so the fit loop is exercised against a modeled encoder.
 */
function fakeCanvas(sizeModel: SizeModel, w = 0, h = 0): HTMLCanvasElement {
    const canvas = {
        width: w,
        height: h,
        getContext: () => ({ drawImage: () => {} }),
        toBlob(
            cb: (blob: Blob | null) => void,
            type: string,
            quality = 1,
        ): void {
            const size = sizeModel(canvas.width, canvas.height, type, quality);
            cb(size === null ? null : ({ size, type } as Blob));
        },
    };

    return canvas as unknown as HTMLCanvasElement;
}

/** Route `document.createElement('canvas')` (used by the downscale step) to fakes. */
function stubDownscaleCanvases(sizeModel: SizeModel): void {
    const real = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) =>
        tag === 'canvas' ? fakeCanvas(sizeModel) : real(tag),
    );
}

const JPEG = { type: 'image/jpeg', quality: 0.92 };
const WEBP = { type: 'image/webp', quality: 0.92 };
const BUDGET = 7.5 * 1024 * 1024;

describe('computeExportScale', () => {
    it('uses the base scale when the result stays under the cap', () => {
        expect(computeExportScale(500, 2048, 2)).toBe(2);
    });

    it('caps the longest edge for large images', () => {
        expect(computeExportScale(2000, 2048, 2)).toBeCloseTo(2048 / 2000);
    });

    it('never returns more than the base scale', () => {
        expect(computeExportScale(10, 2048, 2)).toBe(2);
    });
});

describe('pickComposedFormat', () => {
    it('encodes JPEG when a background makes the composite opaque', () => {
        const settings = {
            ...defaultSettings(),
            background: gradientToFill(GRADIENTS[0]),
        };
        expect(pickComposedFormat(settings).type).toBe('image/jpeg');
    });

    it('encodes WebP (alpha-preserving) when there is no background', () => {
        const settings = { ...defaultSettings(), background: NO_BACKGROUND };
        expect(pickComposedFormat(settings).type).toBe('image/webp');
    });

    it('starts from a high, non-lossless quality', () => {
        const { quality } = pickComposedFormat(defaultSettings());
        expect(quality).toBeGreaterThan(0.8);
        expect(quality).toBeLessThanOrEqual(1);
    });
});

describe('encodeCanvasToFit', () => {
    afterEach(() => vi.restoreAllMocks());

    it('returns the full-resolution encode when it already fits', async () => {
        const canvas = fakeCanvas(() => 1_000, 2048, 1536);

        const blob = await encodeCanvasToFit(canvas, JPEG, BUDGET);

        expect(blob.size).toBeLessThanOrEqual(BUDGET);
        expect(blob.type).toBe('image/jpeg');
    });

    it('downscales — reaching MIN_SCALE (0.5) — and never returns over budget', async () => {
        // 4 bytes/px, quality-independent: only the 0.5 scale (1024×768) fits the
        // budget, so the loop MUST reach 0.5 (the old float step stopped at 0.6).
        const model: SizeModel = (w, h) => w * h * 4;
        stubDownscaleCanvases(model);
        const canvas = fakeCanvas(model, 2048, 1536);
        const budget = 1024 * 768 * 4; // exactly the 0.5-scale size

        const blob = await encodeCanvasToFit(canvas, JPEG, budget);

        expect(blob.size).toBeLessThanOrEqual(budget);
    });

    it('accepts the browser PNG fallback when WebP cannot be encoded', async () => {
        // Simulate a browser that returns null for WebP but encodes small PNGs.
        const model: SizeModel = (_w, _h, type) =>
            type === 'image/webp' ? null : 2_000;
        const canvas = fakeCanvas(model, 800, 600);

        const blob = await encodeCanvasToFit(canvas, WEBP, BUDGET);

        expect(blob.type).toBe('image/png');
        expect(blob.size).toBeLessThanOrEqual(BUDGET);
    });

    it('throws rather than upload an over-budget blob when nothing fits', async () => {
        const model: SizeModel = () => 100 * 1024 * 1024; // always huge
        stubDownscaleCanvases(model);
        const canvas = fakeCanvas(model, 2048, 1536);

        await expect(encodeCanvasToFit(canvas, JPEG, BUDGET)).rejects.toThrow(
            /rasterize/i,
        );
    });
});
