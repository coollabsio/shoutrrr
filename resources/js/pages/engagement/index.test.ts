import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, it } from 'vitest';

it('reserves space for the mobile sheet close button beside reply actions', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'index.tsx'),
        'utf8',
    );

    expect(source).toContain("reserveCloseButtonSpace && 'pr-14'");
    expect(source).toContain('reserveCloseButtonSpace');
});

it('shows disabled engagement platforms to end users', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'index.tsx'),
        'utf8',
    );

    expect(source).toContain('EngagementDisabledBanner');
    expect(source).toContain('Reply polling is temporarily disabled for');
    expect(source).toContain('disabledPlatformLabels');
});

it('uses shared disabled platform label helpers', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'index.tsx'),
        'utf8',
    );
    const platformSource = readFileSync(
        resolve(import.meta.dirname, '../../lib/platforms.ts'),
        'utf8',
    );

    expect(source).not.toContain('const engagementPlatforms');
    expect(source).toContain("from '@/lib/platforms'");
    expect(platformSource).toContain('Object.keys(enabled)');
});
