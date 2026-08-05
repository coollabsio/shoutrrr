import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('Inertia progress indicator color', () => {
    it('uses the same primary color token as default buttons', () => {
        const appSource = readFileSync(
            resolve(process.cwd(), 'resources/js/app.tsx'),
            'utf8',
        );
        const buttonSource = readFileSync(
            resolve(process.cwd(), 'resources/js/components/ui/button.tsx'),
            'utf8',
        );

        // Scoped to the `variant` block so this reads the default *variant*, not
        // the default `size`. Matches anywhere in the class string rather than at
        // its start — the emphasis gradient prepends utilities to it.
        const variantBlock = buttonSource.slice(
            buttonSource.indexOf('variant: {'),
            buttonSource.indexOf('size: {'),
        );

        // `bg-primary-gradient` is spelled out rather than left to a \b boundary,
        // which would match it by accident and make this pass for the wrong
        // reason. Its bottom stop is var(--primary), so either form keeps the
        // progress bar and the default button on the same token.
        expect(variantBlock).toMatch(
            /default:\s*\n?\s*"[^"]*\bbg-primary(-gradient)?[\s"]/,
        );
        expect(appSource).toContain("color: 'var(--primary)'");
    });
});
