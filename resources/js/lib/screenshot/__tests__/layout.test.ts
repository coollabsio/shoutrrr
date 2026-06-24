import { describe, expect, it } from 'vitest';

import {
    aspectToRatio,
    centeredCropForRatio,
    clampCropRect,
    moveCropRect,
    resizeCorner,
    stageDimensions,
} from '../layout';

describe('centeredCropForRatio', () => {
    it('returns a centered square for ratio 1 on a landscape source', () => {
        expect(centeredCropForRatio(800, 600, 1)).toEqual({
            x: 100,
            y: 0,
            width: 600,
            height: 600,
        });
    });

    it('is width-bound for a wide ratio and stays inside the source', () => {
        const r = centeredCropForRatio(800, 600, 16 / 9);
        expect(r.width).toBe(800);
        expect(r.height).toBeCloseTo(450);
        expect(r.y).toBeCloseTo(75);
        expect(r.width / r.height).toBeCloseTo(16 / 9);
    });
});

describe('aspectToRatio', () => {
    it('maps presets to ratios, auto to null', () => {
        expect(aspectToRatio('auto')).toBeNull();
        expect(aspectToRatio('1:1')).toBe(1);
        expect(aspectToRatio('16:9')).toBeCloseTo(16 / 9);
        expect(aspectToRatio('9:16')).toBeCloseTo(9 / 16);
    });
});

describe('stageDimensions', () => {
    it('auto hugs the padded content', () => {
        expect(stageDimensions(800, 600, 50, 'auto')).toEqual({
            width: 900,
            height: 700,
        });
    });

    it('fixed ratio grows the binding axis and keeps the content inside', () => {
        const s = stageDimensions(800, 600, 50, '1:1');
        expect(s.width).toBe(s.height);
        expect(s.width).toBeGreaterThanOrEqual(900);
        expect(s.height).toBeGreaterThanOrEqual(700);
    });

    it('16:9 yields the target ratio', () => {
        const s = stageDimensions(400, 400, 20, '16:9');
        expect(s.width / s.height).toBeCloseTo(16 / 9);
    });
});

describe('clampCropRect', () => {
    it('keeps the rect inside bounds', () => {
        expect(
            clampCropRect({ x: -10, y: -5, width: 50, height: 50 }, 100, 100),
        ).toEqual({
            x: 0,
            y: 0,
            width: 50,
            height: 50,
        });
        const r = clampCropRect(
            { x: 80, y: 80, width: 50, height: 50 },
            100,
            100,
        );
        expect(r.x + r.width).toBeLessThanOrEqual(100);
        expect(r.y + r.height).toBeLessThanOrEqual(100);
    });
});

describe('resizeCorner', () => {
    it('anchors the opposite corner when dragging se', () => {
        const r = resizeCorner(
            { x: 10, y: 10, width: 40, height: 40 },
            'se',
            10,
            20,
            null,
            200,
            200,
        );
        expect(r.x).toBe(10);
        expect(r.y).toBe(10);
        expect(r.width).toBe(50);
        expect(r.height).toBe(60);
    });

    it('honors a locked ratio', () => {
        const r = resizeCorner(
            { x: 0, y: 0, width: 40, height: 40 },
            'se',
            40,
            0,
            1,
            500,
            500,
        );
        expect(r.width).toBeCloseTo(r.height);
    });

    it('never escapes bounds', () => {
        const r = resizeCorner(
            { x: 0, y: 0, width: 40, height: 40 },
            'se',
            9999,
            9999,
            null,
            100,
            100,
        );
        expect(r.x + r.width).toBeLessThanOrEqual(100);
        expect(r.y + r.height).toBeLessThanOrEqual(100);
    });
});

describe('moveCropRect', () => {
    it('translates and clamps to bounds', () => {
        expect(
            moveCropRect(
                { x: 10, y: 10, width: 20, height: 20 },
                5,
                -5,
                100,
                100,
            ),
        ).toEqual({
            x: 15,
            y: 5,
            width: 20,
            height: 20,
        });
        const r = moveCropRect(
            { x: 90, y: 90, width: 20, height: 20 },
            50,
            50,
            100,
            100,
        );
        expect(r.x).toBe(80);
        expect(r.y).toBe(80);
    });
});
