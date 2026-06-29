import type { VideoCodec } from 'mediabunny';

// MP4-compatible codecs the editor can output, in preference order. Each
// mediabunny codec is paired with a representative WebCodecs codec string used
// for the native encode probe.
const PROBE_CODECS: { codec: VideoCodec; config: string }[] = [
    { codec: 'avc', config: 'avc1.42001f' }, // H.264 Baseline 3.1
    { codec: 'av1', config: 'av01.0.04M.08' }, // AV1 Main
    { codec: 'vp9', config: 'vp09.00.10.08' }, // VP9 Profile 0
    { codec: 'hevc', config: 'hvc1.1.6.L93.B0' }, // HEVC Main
];

// Probe results are cached per output resolution for the page's lifetime.
const cache = new Map<string, Promise<VideoCodec | null>>();

/**
 * The first MP4-compatible codec this browser can actually *encode* at the given
 * output resolution, or `null` if none can. Used both to gate the crop UI and to
 * choose the codec for the real render, so the two can never disagree.
 *
 * `VideoEncoder.isConfigSupported` (and mediabunny's codec query, which uses it)
 * is optimistic on some builds — notably Chromium on Linux without proprietary
 * codecs, and software encoders that handle small frames but not full-size ones
 * — so the only reliable check is to actually encode a frame at the real size
 * and confirm a chunk comes out. Native WebCodecs only, so this stays out of
 * mediabunny's lazy-loaded chunk.
 */
export function firstEncodableVideoCodec(
    width: number,
    height: number,
): Promise<VideoCodec | null> {
    // Encoders want even dimensions; normalize so odd sizes don't spuriously
    // fail the probe (mediabunny rounds the real crop the same way).
    const w = Math.max(2, Math.floor(width / 2) * 2);
    const h = Math.max(2, Math.floor(height / 2) * 2);
    const key = `${w}x${h}`;
    let result = cache.get(key);
    if (!result) {
        result = probe(w, h);
        cache.set(key, result);
    }
    return result;
}

async function probe(
    width: number,
    height: number,
): Promise<VideoCodec | null> {
    if (
        typeof window === 'undefined' ||
        !('VideoEncoder' in window) ||
        typeof VideoFrame === 'undefined'
    ) {
        return null;
    }
    for (const { codec, config } of PROBE_CODECS) {
        if (await canEncode(config, width, height)) {
            return codec;
        }
    }
    return null;
}

async function canEncode(
    codec: string,
    width: number,
    height: number,
): Promise<boolean> {
    const config = { codec, width, height, bitrate: 1_000_000, framerate: 30 };
    let encoder: VideoEncoder | null = null;
    let frame: VideoFrame | null = null;
    try {
        const { supported } = await VideoEncoder.isConfigSupported(config);
        // A negative here is reliable — skip the (more expensive) real attempt.
        if (!supported) {
            return false;
        }

        return await new Promise<boolean>((resolve) => {
            encoder = new VideoEncoder({
                // A real encoded chunk is the only success signal.
                output: () => resolve(true),
                error: () => resolve(false),
            });
            encoder.configure(config);

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            canvas.getContext('2d')?.fillRect(0, 0, width, height);
            frame = new VideoFrame(canvas, { timestamp: 0 });
            encoder.encode(frame, { keyFrame: true });

            // If flush settles before any output, treat it as a failure (an
            // `output` callback would already have resolved true).
            void encoder
                .flush()
                .then(() => resolve(false))
                .catch(() => resolve(false));
        });
    } catch {
        return false;
    } finally {
        // Assigned inside the Promise executor (which runs synchronously), so
        // cast past TS's flow narrowing to clean both up.
        try {
            (frame as VideoFrame | null)?.close();
        } catch {
            // already closed
        }
        try {
            (encoder as VideoEncoder | null)?.close();
        } catch {
            // already closed
        }
    }
}
