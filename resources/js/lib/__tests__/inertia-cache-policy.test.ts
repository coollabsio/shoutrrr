import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

import { describe, expect, it } from 'vitest';

function sourceFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        const stat = statSync(path);

        if (stat.isDirectory()) {
            if (entry === '__tests__') {
                return [];
            }

            return sourceFiles(path);
        }

        return /\.(ts|tsx)$/.test(entry) ? [path] : [];
    });
}

describe('Inertia cache policy', () => {
    it('does not use client-side page prefetch caching in app code', () => {
        const offenders = sourceFiles(join(process.cwd(), 'resources/js'))
            .map((path) => ({
                path: relative(process.cwd(), path),
                source: readFileSync(path, 'utf8'),
            }))
            .filter(({ source }) =>
                ['prefetch', 'cacheFor', 'cacheTags', 'flushAll'].some((term) =>
                    source.includes(term),
                ),
            )
            .map(({ path }) => path);

        expect(offenders).toEqual([]);
    });
});
