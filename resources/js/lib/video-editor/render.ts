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
import { firstEncodableVideoCodec } from './support';

export async function renderVideo(
    source: Blob,
    settings: VideoEditSettings,
    onProgress: (fraction: number) => void,
): Promise<Blob> {
    const input = new Input({
        formats: ALL_FORMATS,
        source: new BlobSource(source),
    });

    try {
        const format = new Mp4OutputFormat();
        const output = new Output({
            format,
            target: new BufferTarget(),
        });

        // Trim-only conversions copy the compressed video track (no encoder
        // needed), so leave the video options empty. Cropping needs a pixel
        // transform, which forces a decode → re-encode and therefore a working
        // encoder. Some environments (notably Chromium on Linux built without
        // proprietary codecs) expose the WebCodecs API but can't encode
        // anything — bail with a clear message in that case. Audio is kept
        // automatically because we don't pass `audio: { discard: true }`.
        const video: ConversionVideoOptions = {};
        if (settings.crop) {
            const width = Math.round(settings.crop.width);
            const height = Math.round(settings.crop.height);
            // Pick a codec we've confirmed encodes at this exact output size —
            // same probe that gates the crop UI, so they can't disagree.
            const codec = await firstEncodableVideoCodec(width, height);
            if (!codec) {
                throw new Error(
                    'Your browser can’t encode video, so cropping isn’t available here. Trim without cropping, or upload without editing.',
                );
            }
            video.codec = codec;
            video.bitrate = QUALITY_HIGH;
            video.crop = {
                left: Math.round(settings.crop.x),
                top: Math.round(settings.crop.y),
                width,
                height,
            };
        }

        const conversion = await Conversion.init({
            input,
            output,
            trim: { start: settings.trim.start, end: settings.trim.end },
            video,
        });

        if (!conversion.isValid) {
            throw new Error(
                'This video can’t be edited in the browser. Use “Upload without editing” instead.',
            );
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
