import {
    ALL_FORMATS,
    BlobSource,
    BufferTarget,
    Conversion,
    Input,
    Mp4OutputFormat,
    Output,
} from 'mediabunny';

import { minVideoBytes } from '@/lib/compose/video';
import type { PlatformLimits } from '@/types/compose';

import { compressVideoToFit } from './compress';
import {
    planConversion,
    VideoConvertError,
    type VideoProbe,
} from './convert-plan';

/**
 * Convert a non-MP4 `file` to a platform-ready MP4 (H.264/AAC) entirely in the
 * browser. Remuxes (lossless container swap) when the source is already
 * H.264/AAC within the byte cap; otherwise re-encodes via `compressVideoToFit`.
 * Throws `VideoConvertError` when the file can't be converted. Lazy-imported by
 * the upload hook so mediabunny's chunk stays out of the main bundle.
 */
export async function convertToMp4(
    file: File,
    videoLimits: PlatformLimits[],
    onProgress: (fraction: number) => void,
    signal?: AbortSignal,
): Promise<File> {
    const probe = await probeVideo(file, signal);
    const plan = planConversion(probe, videoLimits);

    if (plan.action === 'reject') {
        throw new VideoConvertError(plan.reason);
    }

    if (plan.action === 'remux') {
        // A remux that reports invalid — or throws outright — falls through to a
        // full re-encode rather than failing a file we already know we can
        // decode. The probe confirmed a decodable track, so re-encoding is worth
        // a try even when the cheap container-copy path errors.
        try {
            const remuxed = await remuxToMp4(file, onProgress, signal);
            if (remuxed) {
                return toMp4File(remuxed, file.name);
            }
        } catch {
            if (signal?.aborted) {
                throw new DOMException('Aborted', 'AbortError');
            }
            // fall through to re-encode
        }
    }

    // Feed the mediabunny-probed metadata to the encoder so it never falls back
    // to the `<video>`-based reader, which can't open .mkv/.avi containers.
    const encoded = await compressVideoToFit(
        file,
        minVideoBytes(videoLimits),
        onProgress,
        {
            sizeBytes: probe.sizeBytes,
            mime: file.type || 'video/mp4',
            durationSeconds: probe.durationSeconds,
            width: probe.width,
            height: probe.height,
        },
        signal,
    );
    if (!encoded) {
        throw new VideoConvertError('encode-unsupported');
    }

    return toMp4File(encoded, file.name);
}

/** Read codec/duration/decodability with mediabunny (no `<video>` element, so
 * it works for containers the browser can't natively play, e.g. `.mkv`).
 * Aborting disposes the input, which cancels any in-flight header reads. */
async function probeVideo(
    file: File,
    signal?: AbortSignal,
): Promise<VideoProbe> {
    const input = new Input({
        formats: ALL_FORMATS,
        source: new BlobSource(file),
    });
    const onAbort = (): void => {
        if (!input.disposed) {
            input.dispose();
        }
    };
    signal?.addEventListener('abort', onAbort, { once: true });
    try {
        const videoTrack = await input.getPrimaryVideoTrack();
        if (!videoTrack) {
            return {
                hasVideoTrack: false,
                canDecodeVideo: false,
                videoCodec: null,
                audioCodec: null,
                durationSeconds: 0,
                width: 0,
                height: 0,
                sizeBytes: file.size,
            };
        }

        const [
            canDecodeVideo,
            videoCodec,
            width,
            height,
            audioTrack,
            duration,
        ] = await Promise.all([
            videoTrack.canDecode(),
            videoTrack.getCodec(),
            videoTrack.getDisplayWidth(),
            videoTrack.getDisplayHeight(),
            input.getPrimaryAudioTrack(),
            input.computeDuration(),
        ]);
        const audioCodec = audioTrack ? await audioTrack.getCodec() : null;

        return {
            hasVideoTrack: true,
            canDecodeVideo,
            videoCodec,
            audioCodec,
            // Floor + clamp to ≥1 to match readVideoMetadata's duration contract
            // (the confirm endpoint rejects a 0-second duration).
            durationSeconds: Math.max(1, Math.floor(duration)),
            width,
            height,
            sizeBytes: file.size,
        };
    } finally {
        signal?.removeEventListener('abort', onAbort);
        if (!input.disposed) {
            input.dispose();
        }
    }
}

/** Repackage `file` into an MP4 container, copying MP4-compatible tracks without
 * re-encoding. Returns `null` when mediabunny reports the conversion invalid.
 * Aborting cancels the conversion, which makes `execute()` throw. */
async function remuxToMp4(
    file: File,
    onProgress: (fraction: number) => void,
    signal?: AbortSignal,
): Promise<Blob | null> {
    const input = new Input({
        formats: ALL_FORMATS,
        source: new BlobSource(file),
    });
    try {
        const output = new Output({
            format: new Mp4OutputFormat(),
            target: new BufferTarget(),
        });
        // No video/audio options → mediabunny copies MP4-compatible tracks
        // (H.264/AAC) and only transcodes anything it must.
        const conversion = await Conversion.init({ input, output });
        if (!conversion.isValid) {
            return null;
        }
        conversion.onProgress = (progress) => onProgress(progress);
        const onAbort = (): void => void conversion.cancel();
        signal?.addEventListener('abort', onAbort, { once: true });
        try {
            await conversion.execute();
        } finally {
            signal?.removeEventListener('abort', onAbort);
        }

        const buffer = output.target.buffer;
        return buffer === null
            ? null
            : new Blob([buffer], { type: 'video/mp4' });
    } finally {
        input.dispose();
    }
}

function toMp4File(blob: Blob, originalName: string): File {
    const base = originalName.replace(/\.[^./\\]+$/, '');
    return new File([blob], `${base}.mp4`, { type: 'video/mp4' });
}
