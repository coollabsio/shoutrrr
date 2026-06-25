import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('crop overlay', () => {
    it('renders the crop frame above the selected image', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/crop-overlay.tsx',
            ),
            'utf8',
        );

        expect(source).toContain(
            'pointer-events-none absolute inset-0 z-10 border-2 border-white',
        );
        expect(source).toContain(
            'absolute z-10 size-5 rounded-full border border-border bg-white shadow-sm md:size-3',
        );
    });
});
