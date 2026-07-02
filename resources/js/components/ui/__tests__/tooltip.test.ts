import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = () =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/components/ui/tooltip.tsx'),
        'utf8',
    );

describe('tooltip', () => {
    it('uses a real Radix arrow filled from the tooltip background', () => {
        const component = source();

        expect(component).toContain('[--tooltip-bg:var(--foreground)]');
        expect(component).toContain('bg-(--tooltip-bg)');
        expect(component).toContain('fill-(--tooltip-bg)');
        expect(component).not.toContain('rotate-45');
        expect(component).not.toContain('bg-foreground fill-foreground');
    });
});
