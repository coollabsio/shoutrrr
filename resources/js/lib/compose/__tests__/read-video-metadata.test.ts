import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { readVideoMetadata } from '@/lib/compose/video';

type Handler = (() => void) | null;

/**
 * A controllable stand-in for an <video> element. jsdom's HTMLMediaElement
 * never fires loadedmetadata/seeked nor populates videoWidth, so the flaky
 * freshly-muxed-blob sequence the hardening targets can't be reproduced with a
 * real element — this fake lets the test drive those events deterministically.
 */
class FakeVideo {
    preload = '';
    muted = false;
    videoWidth = 0;
    videoHeight = 0;
    duration = Number.NaN;
    src = '';
    onloadedmetadata: Handler = null;
    onseeked: Handler = null;
    onerror: Handler = null;
    seeks: number[] = [];
    /** Duration the "browser" resolves once nudged with a seek-to-end. */
    resolvedDuration: number | null = null;
    removeAttribute = vi.fn();
    load = vi.fn();

    private currentTimeValue = 0;

    get currentTime(): number {
        return this.currentTimeValue;
    }

    set currentTime(value: number) {
        this.currentTimeValue = value;
        this.seeks.push(value);
        if (value > 1e50 && this.resolvedDuration !== null) {
            this.duration = this.resolvedDuration;
        }
        queueMicrotask(() => this.onseeked?.());
    }
}

let created: FakeVideo | null = null;

beforeEach(() => {
    created = null;
    // The suite runs in a node environment (no jsdom), so stub the only two DOM
    // globals readVideoMetadata touches rather than pulling in a full DOM.
    vi.stubGlobal('document', {
        createElement: (tag: string) => {
            if (tag === 'video') {
                created = new FakeVideo();

                return created;
            }

            throw new Error(`unexpected createElement(${tag})`);
        },
    });
    vi.stubGlobal('URL', {
        createObjectURL: vi.fn(() => 'blob:fake'),
        revokeObjectURL: vi.fn(),
    });
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

function file(): File {
    return new File([new Uint8Array(1024)], 'v.mp4', { type: 'video/mp4' });
}

it('resolves immediately when the first metadata read is already valid', async () => {
    const promise = readVideoMetadata(file());
    const el = created!;
    el.duration = 12;
    el.videoWidth = 1280;
    el.videoHeight = 720;
    el.onloadedmetadata?.();

    await expect(promise).resolves.toEqual({
        sizeBytes: 1024,
        mime: 'video/mp4',
        durationSeconds: 12,
        width: 1280,
        height: 720,
    });
    // A clean read must not pay for the seek nudge.
    expect(el.seeks).toEqual([]);
});

it('nudges with a seek when duration comes back non-finite, then resolves', async () => {
    const promise = readVideoMetadata(file());
    const el = created!;
    // The flaky case: metadata fires with Infinity duration but real dimensions.
    el.duration = Number.POSITIVE_INFINITY;
    el.videoWidth = 1920;
    el.videoHeight = 1080;
    el.resolvedDuration = 8; // what the browser settles on after the seek
    el.onloadedmetadata?.();

    await expect(promise).resolves.toEqual({
        sizeBytes: 1024,
        mime: 'video/mp4',
        durationSeconds: 8,
        width: 1920,
        height: 1080,
    });
    expect(el.seeks.length).toBeGreaterThan(0);
});

it('nudges when dimensions are missing even if duration is known', async () => {
    const promise = readVideoMetadata(file());
    const el = created!;
    el.duration = 30;
    el.videoWidth = 0;
    el.videoHeight = 0;
    el.onloadedmetadata?.();
    // Dimensions arrive after the frame-decode seek.
    el.videoWidth = 640;
    el.videoHeight = 480;
    // Flush the queued onseeked.
    await Promise.resolve();

    await expect(promise).resolves.toMatchObject({
        durationSeconds: 30,
        width: 640,
        height: 480,
    });
    expect(el.seeks).toContain(0);
});

it('rejects (rather than returning bad data) when the video errors', async () => {
    const promise = readVideoMetadata(file());
    created!.onerror?.();

    await expect(promise).rejects.toThrow('Could not read video metadata');
});

it('rejects when a nudge still cannot produce valid metadata', async () => {
    const promise = readVideoMetadata(file());
    const el = created!;
    el.duration = Number.NaN;
    el.videoWidth = 0;
    el.videoHeight = 0;
    el.onloadedmetadata?.(); // triggers a seek; duration stays NaN

    await expect(promise).rejects.toThrow('Could not read video metadata');
});
