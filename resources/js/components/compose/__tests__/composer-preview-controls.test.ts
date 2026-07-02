import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = () =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/components/compose/composer.tsx'),
        'utf8',
    );

describe('composer preview controls', () => {
    it('adds an accessible pin toggle to the preview button group', () => {
        const composer = source();

        expect(composer).toContain('const PREVIEW_PINNED_STORAGE_KEY');
        expect(composer).toContain(
            'window.localStorage.getItem(PREVIEW_PINNED_STORAGE_KEY)',
        );
        expect(composer).toContain('window.localStorage.setItem');
        expect(composer).toContain('String(previewPinned)');
        expect(composer).toContain(
            'const previewVisible = showPreview || previewPinned',
        );
        expect(composer).toContain('aria-label="Pin platform preview"');
        expect(composer).toContain('aria-pressed={previewPinned}');
        expect(composer).toContain('<Pin className="size-3.5 shrink-0" />');
    });

    it('lets the mobile tab row use the full header width', () => {
        const composer = source();

        expect(composer).toContain('flex-wrap items-center gap-y-2');
        expect(composer).toContain('flex-[1_1_100%]');
        expect(composer).toContain('md:flex-nowrap');
        expect(composer).toContain('hidden sm:inline');
    });
});
