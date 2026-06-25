import type { BackgroundFill } from './gradients';
import { findGradient, gradientToFill, GRADIENTS } from './gradients';

export type ShadowPreset = 'none' | 'soft' | 'medium' | 'strong';
export type AspectPreset = 'auto' | '1:1' | '4:3' | '3:4' | '16:9' | '9:16';

export type CropRect = { x: number; y: number; width: number; height: number };

export type EditSettings = {
    version: 1;
    background: BackgroundFill;
    padding: number;
    radius: number;
    shadow: ShadowPreset;
    aspect: AspectPreset;
    /** Scale applied to the (cropped) image within the frame; 1 = 100%. */
    zoom: number;
    tilt: { rotateX: number; rotateY: number };
    crop: CropRect | null;
};

export const ZOOM_MIN = 0.5;
export const ZOOM_MAX = 2;

function clampZoom(zoom: number): number {
    return Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, zoom));
}

export const SHADOW_PRESETS: readonly ShadowPreset[] = [
    'none',
    'soft',
    'medium',
    'strong',
];

export const ASPECT_PRESETS: readonly AspectPreset[] = [
    'auto',
    '1:1',
    '4:3',
    '3:4',
    '16:9',
    '9:16',
];

export function defaultSettings(): EditSettings {
    // Plain by default — a basic crop/aspect tool. Gradient background, padding,
    // radius, shadow and 3D tilt are opt-in via the editor's Advanced controls
    // (the background only becomes visible once padding is added).
    return {
        version: 1,
        background: gradientToFill(GRADIENTS[0]),
        padding: 0,
        radius: 0,
        shadow: 'none',
        aspect: 'auto',
        zoom: 1,
        tilt: { rotateX: 0, rotateY: 0 },
        crop: null,
    };
}

function asRecord(value: unknown): Record<string, unknown> {
    return value && typeof value === 'object'
        ? (value as Record<string, unknown>)
        : {};
}

function numberOr(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value)
        ? value
        : fallback;
}

function normalizeBackground(raw: unknown): BackgroundFill {
    const rec = asRecord(raw);
    const preset =
        (typeof rec.id === 'string' && findGradient(rec.id)) || GRADIENTS[0];

    return gradientToFill(preset);
}

function normalizeCrop(raw: unknown): CropRect | null {
    if (!raw || typeof raw !== 'object') {
        return null;
    }
    const rec = raw as Record<string, unknown>;
    if (['x', 'y', 'width', 'height'].some((k) => typeof rec[k] !== 'number')) {
        return null;
    }

    return {
        x: rec.x as number,
        y: rec.y as number,
        width: rec.width as number,
        height: rec.height as number,
    };
}

export function normalizeSettings(raw: unknown): EditSettings {
    const d = defaultSettings();
    const rec = asRecord(raw);
    const tilt = asRecord(rec.tilt);

    return {
        version: 1,
        background: normalizeBackground(rec.background),
        padding: numberOr(rec.padding, d.padding),
        radius: numberOr(rec.radius, d.radius),
        shadow: SHADOW_PRESETS.includes(rec.shadow as ShadowPreset)
            ? (rec.shadow as ShadowPreset)
            : d.shadow,
        aspect: ASPECT_PRESETS.includes(rec.aspect as AspectPreset)
            ? (rec.aspect as AspectPreset)
            : d.aspect,
        zoom: clampZoom(numberOr(rec.zoom, d.zoom)),
        tilt: {
            rotateX: numberOr(tilt.rotateX, 0),
            rotateY: numberOr(tilt.rotateY, 0),
        },
        crop: normalizeCrop(rec.crop),
    };
}
