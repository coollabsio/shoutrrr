// MP4-compatible video codecs the editor can output, each paired with a
// representative codec string for the native VideoEncoder probe. The editor
// only needs ONE of these to be encodable.
const PROBE_CODECS = [
    'avc1.42001f', // H.264 Baseline 3.1
    'av01.0.04M.08', // AV1 Main
    'vp09.00.10.08', // VP9 Profile 0
    'hvc1.1.6.L93.B0', // HEVC Main
];

let cachedSupport: Promise<boolean> | null = null;

/**
 * Whether this browser can *encode* video via WebCodecs — i.e. whether the
 * editor can crop (cropping forces a re-encode; trimming only copies the track
 * and needs no encoder). The mere presence of the VideoEncoder API object isn't
 * enough — many Linux Chromium builds expose it with no usable encoder behind it
 * — so we probe `VideoEncoder.isConfigSupported` for at least one MP4-compatible
 * codec. The result is cached for the page's lifetime. This uses the native API
 * only, so it stays out of mediabunny's lazy-loaded chunk.
 */
export function isVideoEncodingSupported(): Promise<boolean> {
    cachedSupport ??= probeSupport();
    return cachedSupport;
}

async function probeSupport(): Promise<boolean> {
    if (
        typeof window === 'undefined' ||
        !('VideoEncoder' in window) ||
        !('VideoDecoder' in window)
    ) {
        return false;
    }
    for (const codec of PROBE_CODECS) {
        try {
            const { supported } = await VideoEncoder.isConfigSupported({
                codec,
                width: 1280,
                height: 720,
                bitrate: 1_000_000,
            });
            if (supported) {
                return true;
            }
        } catch {
            // Malformed config / unsupported codec — try the next one.
        }
    }
    return false;
}
