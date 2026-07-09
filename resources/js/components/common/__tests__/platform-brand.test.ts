import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const files = [
    'resources/js/components/accounts/account-card.tsx',
    'resources/js/components/compose/destination-selector.tsx',
    'resources/js/components/compose/platform-tabs.tsx',
];

const opaquePlatformStyles = [
    "x: { tile: 'bg-white', glyph: 'text-black!' }",
    "linkedin: { tile: 'bg-blue-600', glyph: 'text-white!' }",
    "bluesky: { tile: 'bg-sky-500', glyph: 'text-white!' }",
    "facebook: { tile: 'bg-[#1877F2]', glyph: 'text-white!' }",
    "instagram: { tile: 'bg-[#E4405F]', glyph: 'text-white!' }",
    "threads: { tile: 'bg-black', glyph: 'text-white!' }",
];

describe('platform badge styles', () => {
    it.each(files)('%s gives platform icons opaque backgrounds', (file) => {
        const source = readFileSync(resolve(process.cwd(), file), 'utf8');

        for (const style of opaquePlatformStyles) {
            expect(source).toContain(style);
        }

        expect(source).not.toContain("x: { tile: 'bg-foreground/5'");
        expect(source).not.toContain("tile: 'bg-blue-500/10'");
        expect(source).not.toContain("tile: 'bg-sky-500/10'");
    });

    it('puts the protected glyph color on the SVG, not only the wrapper', () => {
        const accountCard = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/accounts/account-card.tsx',
            ),
            'utf8',
        );
        const destinationSelector = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/destination-selector.tsx',
            ),
            'utf8',
        );
        const platformTabs = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/platform-tabs.tsx',
            ),
            'utf8',
        );

        expect(accountCard).toContain('className={brand.glyph}');
        expect(destinationSelector).toContain(
            "className={cn('size-1.5', brand.glyph)}",
        );
        expect(platformTabs).toContain('className={brand.glyph}');
    });
});
