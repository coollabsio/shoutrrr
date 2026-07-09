import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, it } from 'vitest';

it('resets the scrollable replies prop when filters change', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'reply-filters.tsx'),
        'utf8',
    );

    expect(source).toContain("reset: ['replies']");
});
