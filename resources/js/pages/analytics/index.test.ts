import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { disabledPlatformLabels } from '@/lib/platforms';

const source = readFileSync(resolve(import.meta.dirname, 'index.tsx'), 'utf8');

describe('analytics disabled metric notices', () => {
    it('shows partial platform disables where metrics are used', () => {
        expect(source).toContain('{!metricsDisabled && (');
        expect(source).toContain('AnalyticsPollingBanner');
        expect(source).toContain('Some analytics are temporarily disabled');
        expect(source).toContain(
            'Some account metrics are temporarily disabled',
        );
        expect(source).toContain('Some post metrics are temporarily disabled');
        expect(source).toContain('account metrics temporarily disabled');
    });

    it('derives disabled platform labels from the polling payload keys', () => {
        // The notices are built via disabledPlatformLabels, which derives the
        // set from the payload keys instead of a hardcoded platform list.
        expect(source).not.toContain(
            "const analyticsPlatforms: PlatformName[] = ['x', 'bluesky', 'linkedin'];",
        );
        expect(source).toContain('disabledPlatformLabels');

        expect(
            disabledPlatformLabels({
                x: true,
                bluesky: false,
                linkedin: false,
                facebook: true,
                instagram: true,
                threads: true,
            }),
        ).toEqual(['Bluesky', 'LinkedIn']);
        expect(
            disabledPlatformLabels({
                x: true,
                bluesky: true,
                linkedin: true,
                facebook: true,
                instagram: true,
                threads: true,
            }),
        ).toEqual([]);
    });
});

describe('analytics post comparison links', () => {
    it('links comparison rows to the post detail route', () => {
        expect(source).toContain(
            "import { show as postRoute } from '@/routes/posts';",
        );
        expect(source).toContain('href={postRoute(row.id).url}');
    });
});
