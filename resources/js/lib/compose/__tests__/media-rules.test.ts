import { describe, expect, it } from 'vitest';

import {
    wouldMixVideoAndImages,
    wouldViolateBlueskyGif,
} from '@/lib/compose/media-rules';
import type { MediaView } from '@/types/compose';

function media(mime: string): Pick<MediaView, 'mime'> {
    return { mime };
}

function attached(kind: MediaView['kind']): Pick<MediaView, 'kind'> {
    return { kind };
}

function file(type: string): File {
    return new File([''], 'f', { type });
}

const gif = () => file('image/gif');
const png = () => file('image/png');
const mp4 = () => file('video/mp4');

describe('wouldViolateBlueskyGif', () => {
    it('allows a single GIF on its own', () => {
        expect(wouldViolateBlueskyGif([], [gif()])).toBe(false);
    });

    it('allows images with no GIF', () => {
        expect(wouldViolateBlueskyGif([media('image/png')], [png()])).toBe(
            false,
        );
    });

    it('allows an empty batch', () => {
        expect(wouldViolateBlueskyGif([], [])).toBe(false);
    });

    it('blocks a GIF dropped alongside an image in the same batch', () => {
        expect(wouldViolateBlueskyGif([], [gif(), png()])).toBe(true);
    });

    it('blocks a GIF added to already-attached media', () => {
        expect(wouldViolateBlueskyGif([media('image/png')], [gif()])).toBe(
            true,
        );
    });

    it('blocks other media added to an already-attached GIF', () => {
        expect(wouldViolateBlueskyGif([media('image/gif')], [png()])).toBe(
            true,
        );
    });

    it('blocks a second GIF', () => {
        expect(wouldViolateBlueskyGif([media('image/gif')], [gif()])).toBe(
            true,
        );
        expect(wouldViolateBlueskyGif([], [gif(), gif()])).toBe(true);
    });

    it('blocks a GIF mixed with a video', () => {
        expect(wouldViolateBlueskyGif([media('video/mp4')], [gif()])).toBe(
            true,
        );
        expect(wouldViolateBlueskyGif([], [gif(), mp4()])).toBe(true);
    });
});

describe('wouldMixVideoAndImages', () => {
    it('allows images only', () => {
        expect(wouldMixVideoAndImages([], [png(), gif()])).toBe(false);
        expect(wouldMixVideoAndImages([attached('image')], [png()])).toBe(
            false,
        );
    });

    it('allows a single video on its own', () => {
        expect(wouldMixVideoAndImages([], [mp4()])).toBe(false);
    });

    it('allows an empty batch', () => {
        expect(wouldMixVideoAndImages([], [])).toBe(false);
    });

    it('blocks a video dropped alongside an image', () => {
        expect(wouldMixVideoAndImages([], [mp4(), png()])).toBe(true);
    });

    it('blocks a video added to attached images', () => {
        expect(wouldMixVideoAndImages([attached('image')], [mp4()])).toBe(true);
    });

    it('blocks images added to an attached video', () => {
        expect(wouldMixVideoAndImages([attached('video')], [png()])).toBe(true);
    });

    // The batch-level rule only guards against mixing kinds; a second video is
    // caught later by the per-file upload loop, not here.
    it('does not block a second video at the batch level', () => {
        expect(wouldMixVideoAndImages([attached('video')], [mp4()])).toBe(
            false,
        );
    });
});
