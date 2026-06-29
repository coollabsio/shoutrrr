import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    shouldConvertEmptyParagraphToSectionBreak,
    shouldDeleteSectionBreakWithTrailingEmptyParagraph,
} from '@/lib/compose/tiptap/section-break';

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

describe('double-enter post splitting', () => {
    it('turns Enter on an empty paragraph after text into a section break', () => {
        expect(
            shouldConvertEmptyParagraphToSectionBreak({
                currentBlockType: 'paragraph',
                currentText: '',
                previousBlockType: 'paragraph',
            }),
        ).toBe(true);
    });

    it('leaves the first empty line and repeated blank lines alone', () => {
        expect(
            shouldConvertEmptyParagraphToSectionBreak({
                currentBlockType: 'paragraph',
                currentText: '',
                previousBlockType: null,
            }),
        ).toBe(false);
        expect(
            shouldConvertEmptyParagraphToSectionBreak({
                currentBlockType: 'paragraph',
                currentText: '',
                previousBlockType: 'sectionBreak',
            }),
        ).toBe(false);
    });

    it('does not interrupt normal Enter behavior while text is present', () => {
        expect(
            shouldConvertEmptyParagraphToSectionBreak({
                currentBlockType: 'paragraph',
                currentText: 'still writing',
                previousBlockType: 'paragraph',
            }),
        ).toBe(false);
    });
});

describe('delete empty line after post split', () => {
    it('deletes the split when Backspace is pressed in its trailing empty paragraph', () => {
        expect(
            shouldDeleteSectionBreakWithTrailingEmptyParagraph({
                currentBlockType: 'paragraph',
                currentText: '',
                previousBlockType: 'sectionBreak',
            }),
        ).toBe(true);
    });

    it('keeps normal Backspace behavior outside the trailing empty split paragraph', () => {
        expect(
            shouldDeleteSectionBreakWithTrailingEmptyParagraph({
                currentBlockType: 'paragraph',
                currentText: 'next post',
                previousBlockType: 'sectionBreak',
            }),
        ).toBe(false);
        expect(
            shouldDeleteSectionBreakWithTrailingEmptyParagraph({
                currentBlockType: 'paragraph',
                currentText: '',
                previousBlockType: 'paragraph',
            }),
        ).toBe(false);
    });
});

describe('composer editor text rhythm', () => {
    it('matches preview line spacing without paragraph margins', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/editor-body.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('leading-5');
        expect(source).toContain('[&_.ProseMirror_p]:m-0');
        expect(source).toContain('[&_.ProseMirror_p+p]:mt-0');
    });
});
