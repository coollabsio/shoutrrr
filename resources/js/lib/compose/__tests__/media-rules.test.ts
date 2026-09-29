import { describe, expect, it } from 'vitest';

import {
    isAttachOnlyImage,
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

describe('isAttachOnlyImage', () => {
    const withSettings = (
        mime: string,
        editSettings: MediaView['edit_settings'],
    ): Pick<MediaView, 'mime' | 'edit_settings'> => ({
        mime,
        edit_settings: editSettings,
    });
    // A beautifier output always carries edit_settings; the shape doesn't matter
    // to the rule, only its non-null presence.
    const beautified = { version: 1 } as MediaView['edit_settings'];

    it('treats a GIF as attach-only', () => {
        expect(isAttachOnlyImage(withSettings('image/gif', null))).toBe(true);
    });

    it('treats a WebP without edit_settings (GIF-browser attach) as attach-only', () => {
        expect(isAttachOnlyImage(withSettings('image/webp', null))).toBe(true);
    });

    it('treats a beautified WebP (has edit_settings) as editable', () => {
        expect(isAttachOnlyImage(withSettings('image/webp', beautified))).toBe(
            false,
        );
    });

    it('treats JPEG and PNG as editable', () => {
        expect(isAttachOnlyImage(withSettings('image/jpeg', null))).toBe(false);
        expect(isAttachOnlyImage(withSettings('image/png', null))).toBe(false);
    });
});
