import { describe, expect, it } from 'vitest';

import {
    hasPasteableMedia,
    isPasteableMediaFile,
    shouldFocusEditorOnMount,
} from '../editor-body';

function file(type: string): File {
    return new File(['x'], 'clip', { type });
}

function fileList(...files: File[]): FileList {
    const indexed: Record<number, File> = {};
    files.forEach((f, i) => {
        indexed[i] = f;
    });

    return {
        ...indexed,
        length: files.length,
        item: (i: number) => files[i] ?? null,
        [Symbol.iterator]: () => files[Symbol.iterator](),
    } as unknown as FileList;
}

describe('shouldFocusEditorOnMount', () => {
    it('focuses only when autofocus is requested and the editor is editable', () => {
        expect(shouldFocusEditorOnMount(true, true)).toBe(true);
        expect(shouldFocusEditorOnMount(false, true)).toBe(false);
        expect(shouldFocusEditorOnMount(true, false)).toBe(false);
    });
});

describe('isPasteableMediaFile', () => {
    it('accepts images and videos, rejects everything else', () => {
        expect(isPasteableMediaFile(file('image/png'))).toBe(true);
        expect(isPasteableMediaFile(file('video/mp4'))).toBe(true);
        expect(isPasteableMediaFile(file('text/plain'))).toBe(false);
        expect(isPasteableMediaFile(file('application/pdf'))).toBe(false);
    });
});

describe('hasPasteableMedia', () => {
    it('is true when at least one pasted file is an image or video', () => {
        expect(hasPasteableMedia(fileList(file('image/jpeg')))).toBe(true);
        expect(
            hasPasteableMedia(fileList(file('text/plain'), file('video/mp4'))),
        ).toBe(true);
    });

    it('is false for an empty, null, or text-only clipboard', () => {
        expect(hasPasteableMedia(fileList())).toBe(false);
        expect(hasPasteableMedia(null)).toBe(false);
        expect(hasPasteableMedia(undefined)).toBe(false);
        expect(hasPasteableMedia(fileList(file('text/html')))).toBe(false);
    });
});
