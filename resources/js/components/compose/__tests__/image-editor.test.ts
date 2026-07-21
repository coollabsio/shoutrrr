import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('image editor crop controls', () => {
    it('uses the footer primary action to finish cropping', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/image-editor.tsx',
            ),
            'utf8',
        );

        expect(source).toMatch(
            /const primaryLabel = cropMode\s+\? 'Done cropping'/,
        );
        expect(source).toMatch(
            /const primaryAction = cropMode\s+\? \(\) => setCropMode\(false\)/,
        );
        expect(source).toContain('{!cropMode && (');
        expect(source).not.toContain(
            "{cropMode ? 'Done cropping' : 'Crop image'}",
        );
    });
});

describe('image editor unedited primary action', () => {
    it('skips re-encoding and drops the redundant skip button when nothing is edited', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/image-editor.tsx',
            ),
            'utf8',
        );

        // With no edits the primary button takes the "keep as-is" path (onCancel)
        // rather than rasterizing the unchanged image.
        expect(source).toMatch(/const isEdited =\s+altText !==/);
        expect(source).toMatch(/: isEdited\s+\? apply\s+: onCancel;/);
        // The separate skip/cancel button only shows once there's something to discard.
        expect(source).toContain('{isEdited && (');
    });
});

describe('image editor background controls', () => {
    it('shows the no-background option before gradient presets', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/image-editor.tsx',
            ),
            'utf8',
        );

        const noneIndex = source.indexOf('aria-label="No background"');
        const gradientsIndex = source.indexOf('GRADIENTS.map');

        expect(noneIndex).toBeGreaterThan(-1);
        expect(gradientsIndex).toBeGreaterThan(noneIndex);
    });
});

describe('image editor default focus', () => {
    it('focuses the primary upload/apply button instead of the close control', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/image-editor.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('initialFocus={primaryButtonRef}');
        expect(source).toContain('ref={primaryButtonRef}');
        expect(source).toContain('primaryButtonRef.current?.focus()');
    });
});
