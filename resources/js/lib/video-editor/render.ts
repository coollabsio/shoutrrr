import {
    ALL_FORMATS,
    BlobSource,
    BufferTarget,
    Conversion,
    Input,
    Mp4OutputFormat,
    Output,
    QUALITY_HIGH,
    type ConversionVideoOptions,
} from 'mediabunny';

import type { VideoEditSettings } from './settings';

export function isVideoEditingSupported(): boolean {
    return typeof window !== 'undefined' && 'VideoEncoder' in window && 'VideoDecoder' in window;
}

export async function renderVideo(
    source: Blob,
    settings: VideoEditSettings,
    onProgress: (fraction: number) => void,
): Promise<Blob> {
    if (!isVideoEditingSupported()) {
        throw new Error('Video editing is not supported in this browser.');
    }

    const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(source) });

    try {
        const output = new Output({ format: new Mp4OutputFormat(), target: new BufferTarget() });

        // Re-encode to H.264 mp4; only add a crop rect when one is set. Audio is
        // kept automatically because we don't pass `audio: { discard: true }`.
        const video: ConversionVideoOptions = { codec: 'avc', bitrate: QUALITY_HIGH };
        if (settings.crop) {
            video.crop = {
                left: Math.round(settings.crop.x),
                top: Math.round(settings.crop.y),
                width: Math.round(settings.crop.width),
                height: Math.round(settings.crop.height),
            };
        }

        const conversion = await Conversion.init({
            input,
            output,
            trim: { start: settings.trim.start, end: settings.trim.end },
            video,
        });

        if (!conversion.isValid) {
            throw new Error('This video cannot be edited in the browser.');
        }

        conversion.onProgress = (progress) => onProgress(progress);

        await conversion.execute();

        const buffer = output.target.buffer;
        if (buffer === null) {
            throw new Error('Rendering produced no output.');
        }

        return new Blob([buffer], { type: 'video/mp4' });
    } finally {
        input.dispose();
    }
}
