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
        expect(source).toContain(
            'const primaryAction = cropMode ? () => setCropMode(false) : apply;',
        );
        expect(source).toContain('{!cropMode && (');
        expect(source).not.toContain(
            "{cropMode ? 'Done cropping' : 'Crop image'}",
        );
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
