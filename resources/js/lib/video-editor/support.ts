// MP4-compatible video codecs the editor can output, each with a representative
// codec string for the native VideoEncoder probe. The editor only needs ONE of
// these to be encodable.
const PROBE_CODECS = [
    'avc1.42001f', // H.264 Baseline 3.1
    'av01.0.04M.08', // AV1 Main
    'vp09.00.10.08', // VP9 Profile 0
    'hvc1.1.6.L93.B0', // HEVC Main
];

const PROBE_CONFIG = {
    width: 320,
    height: 240,
    bitrate: 1_000_000,
    framerate: 30,
};

let cachedSupport: Promise<boolean> | null = null;

/**
 * Whether this browser can *encode* video via WebCodecs — i.e. whether the
 * editor can crop (cropping forces a re-encode; trimming only copies the track
 * and needs no encoder). The result is cached for the page's lifetime, and the
 * probe uses the native WebCodecs API only, so it stays out of mediabunny's
 * lazy-loaded chunk.
 */
export function isVideoEncodingSupported(): Promise<boolean> {
    cachedSupport ??= probeSupport();
    return cachedSupport;
}

async function probeSupport(): Promise<boolean> {
    if (
        typeof window === 'undefined' ||
        !('VideoEncoder' in window) ||
        typeof VideoFrame === 'undefined'
    ) {
        return false;
    }
    for (const codec of PROBE_CODECS) {
        if (await canEncode(codec)) {
            return true;
        }
    }
    return false;
}

/**
 * `VideoEncoder.isConfigSupported` is optimistic on some builds (notably
 * Chromium on Linux without proprietary codecs): it reports a codec as supported
 * even though no real encoder exists, so the encode later fails. The only
 * reliable check is to actually encode one frame and confirm a chunk comes out.
 */
async function canEncode(codec: string): Promise<boolean> {
    let encoder: VideoEncoder | null = null;
    let frame: VideoFrame | null = null;
    try {
        const { supported } = await VideoEncoder.isConfigSupported({
            codec,
            ...PROBE_CONFIG,
        });
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
            encoder.configure({ codec, ...PROBE_CONFIG });

            const canvas = document.createElement('canvas');
            canvas.width = PROBE_CONFIG.width;
            canvas.height = PROBE_CONFIG.height;
            canvas
                .getContext('2d')
                ?.fillRect(0, 0, PROBE_CONFIG.width, PROBE_CONFIG.height);
            frame = new VideoFrame(canvas, { timestamp: 0 });
            encoder.encode(frame, { keyFrame: true });

            // If flush settles before any output was produced, treat it as a
            // failure (an `output` callback would already have resolved true).
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
