import {
    ALL_FORMATS,
    BlobSource,
    BufferTarget,
    Conversion,
    Input,
    Mp4OutputFormat,
    Output,
    type ConversionVideoOptions,
    type VideoCodec,
} from 'mediabunny';

import {
    nextDownscale,
    planVideoEncode,
    readVideoMetadata,
    type VideoMeta,
    VIDEO_MIN_BITRATE,
} from '@/lib/compose/video';

import { firstEncodableVideoCodec } from './support';

/** Single-pass bitrate targeting is approximate, so allow a couple of corrective passes. */
const MAX_ATTEMPTS = 3;
/** Aim comfortably under the cap on the corrective pass so a near-miss doesn't loop. */
const RETRY_HEADROOM = 0.9;
/** Fraction of the remaining progress bar each encode pass fills (keeps it monotonic). */
const PROGRESS_BAND = 0.9;

type EncodeParams = {
    codec: VideoCodec;
    width: number;
    height: number;
    videoBitrate: number;
    audioBitrate: number;
};

/**
 * Re-encode `source` to fit within `maxBytes`, reusing the editor's mediabunny
 * pipeline and encoder-capability probe. Returns the fitting `Blob`, or `null`
 * when the browser can't encode or no resolution/bitrate combination fits (the
 * caller then rejects with the same "too large" message as before).
 *
 * Lazy-imported (this pulls in mediabunny's chunk) the same way `render.ts` is.
 */
export async function compressVideoToFit(
    source: Blob,
    maxBytes: number,
    onProgress: (fraction: number) => void,
    knownMeta?: VideoMeta,
): Promise<Blob | null> {
    // A caller that already probed the source (the format-conversion path reads
    // metadata via mediabunny) passes it in: the `<video>`-based
    // `readVideoMetadata` can't open containers the browser won't natively play
    // (.mkv/.avi), so the transcode path must not depend on it.
    const meta =
        knownMeta ??
        (await readVideoMetadata(
            source instanceof File
                ? source
                : new File([source], 'video.mp4', { type: 'video/mp4' }),
        ));
    const plan = planVideoEncode(meta, maxBytes);

    let { width, height, videoBitrate } = plan;
    const audioBitrate = plan.audioBitrate;

    // mediabunny reports 0..1 per pass, so each corrective retry would restart at
    // 0. Map every pass into a shrinking band above the previous one (0→.9,
    // .9→.99, …) so the chip's percentage only ever climbs.
    let progressFloor = 0;

    for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt += 1) {
        // Confirm an encoder that actually works at this exact output size —
        // the same probe gating the crop UI, so they can't disagree.
        const codec = await firstEncodableVideoCodec(width, height);
        if (!codec) {
            return null;
        }

        const base = progressFloor;
        const span = (1 - base) * PROGRESS_BAND;
        progressFloor = base + span;
        const blob = await encodeOnce(
            source,
            { codec, width, height, videoBitrate, audioBitrate },
            (fraction) => onProgress(base + fraction * span),
        );
        if (blob === null) {
            return null;
        }
        if (blob.size <= maxBytes) {
            return blob;
        }

        // Overshot the cap: scale the bitrate by the observed ratio and drop a
        // resolution step. Give up once we hit the dimension floor.
        videoBitrate = Math.max(
            Math.floor(videoBitrate * (maxBytes / blob.size) * RETRY_HEADROOM),
            VIDEO_MIN_BITRATE,
        );
        const smaller = nextDownscale(width, height);
        if (!smaller) {
            return null;
        }
        width = smaller.width;
        height = smaller.height;
    }

    return null;
}

async function encodeOnce(
    source: Blob,
    params: EncodeParams,
    onProgress: (fraction: number) => void,
): Promise<Blob | null> {
    const input = new Input({
        formats: ALL_FORMATS,
        source: new BlobSource(source),
    });

    try {
        const output = new Output({
            format: new Mp4OutputFormat(),
            target: new BufferTarget(),
        });

        const video: ConversionVideoOptions = {
            codec: params.codec,
            bitrate: params.videoBitrate,
            width: params.width,
            height: params.height,
            fit: 'contain',
            forceTranscode: true,
        };

        const conversion = await Conversion.init({
            input,
            output,
            video,
            // No-op when the source has no audio track.
            audio: { bitrate: params.audioBitrate, forceTranscode: true },
        });

        if (!conversion.isValid) {
            return null;
        }

        conversion.onProgress = (progress) => onProgress(progress);
        await conversion.execute();

        const buffer = output.target.buffer;
        return buffer === null
            ? null
            : new Blob([buffer], { type: 'video/mp4' });
    } finally {
        input.dispose();
    }
}
