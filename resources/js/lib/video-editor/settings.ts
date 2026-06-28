import type { CropRect } from '@/lib/image-editor/settings';

import { type VideoAspectPreset, VIDEO_ASPECT_PRESETS } from './aspects';

export type VideoEditSettings = {
    version: 1;
    trim: { start: number; end: number };
    crop: CropRect | null;
    aspect: VideoAspectPreset;
};

function safeDuration(durationSeconds: number): number {
    return Number.isFinite(durationSeconds) && durationSeconds > 0
        ? durationSeconds
        : 0;
}

export function defaultSettings(durationSeconds: number): VideoEditSettings {
    const end = safeDuration(durationSeconds);
    return {
        version: 1,
        trim: { start: 0, end },
        crop: null,
        aspect: 'auto',
    };
}

function isAspect(value: unknown): value is VideoAspectPreset {
    return VIDEO_ASPECT_PRESETS.some((preset) => preset.value === value);
}

function normalizeCrop(raw: unknown): CropRect | null {
    if (raw === null || typeof raw !== 'object') {
        return null;
    }
    const candidate = raw as Record<string, unknown>;
    const keys = ['x', 'y', 'width', 'height'] as const;
    if (
        !keys.every(
            (key) =>
                typeof candidate[key] === 'number' &&
                Number.isFinite(candidate[key]),
        )
    ) {
        return null;
    }
    return {
        x: candidate.x as number,
        y: candidate.y as number,
        width: candidate.width as number,
        height: candidate.height as number,
    };
}

export function normalizeSettings(
    raw: unknown,
    durationSeconds: number,
): VideoEditSettings {
    const fallback = defaultSettings(durationSeconds);
    if (raw === null || typeof raw !== 'object') {
        return fallback;
    }
    const candidate = raw as Record<string, unknown>;
    const trim = candidate.trim as Record<string, unknown> | undefined;
    const duration = safeDuration(durationSeconds);

    const clamp = (value: unknown): number => {
        if (typeof value !== 'number' || !Number.isFinite(value)) {
            return 0;
        }
        return Math.min(Math.max(value, 0), duration);
    };

    let start = clamp(trim?.start);
    let end = trim?.end === undefined ? duration : clamp(trim.end);
    if (start > end) {
        [start, end] = [end, start];
    }

    return {
        version: 1,
        trim: { start, end },
        crop: normalizeCrop(candidate.crop),
        aspect: isAspect(candidate.aspect) ? candidate.aspect : 'auto',
    };
}
