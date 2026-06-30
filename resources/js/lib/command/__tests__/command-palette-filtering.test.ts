import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

/** Collapse runs of whitespace so assertions don't depend on formatter indentation. */
function normalize(source: string): string {
    return source.replace(/\s+/g, ' ');
}

describe('command palette filtering', () => {
    const palette = normalize(
        readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/layout/command-palette.tsx',
            ),
            'utf8',
        ),
    );
    const postsGroup = normalize(
        readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/layout/command-palette/posts-group.tsx',
            ),
            'utf8',
        ),
    );

    it('relies on cmdk built-in filtering rather than a custom filter', () => {
        expect(palette).not.toContain('shouldFilter={false}');
        expect(palette).not.toContain('filter={');
    });

    it('keeps async date jumps searchable via the live query keyword', () => {
        expect(palette).toContain(
            'keywords={[ trimmed, dateJump.label, dateJump.yyyymm, ]}',
        );
    });

    it('keeps accounts matched by display name or platform searchable', () => {
        // matchedAccounts filters on handle + display_name + platform, but the
        // item value only carries the handle, so the same fields must be
        // mirrored into keywords or cmdk would filter those matches back out.
        expect(palette).toContain(
            "keywords={[ trimmed, account.display_name ?? '', account.platform, ]}",
        );
    });

    it('keeps async posts searchable and never force-mounts them', () => {
        expect(postsGroup).toContain('const keywords = [query];');
        expect(postsGroup).toContain('keywords={keywords}');
        expect(postsGroup).not.toContain('forceMount');
    });
});
