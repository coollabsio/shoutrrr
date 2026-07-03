import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve(import.meta.dirname, 'index.tsx'), 'utf8');

describe('analytics disabled metric notices', () => {
    it('shows partial platform disables where metrics are used', () => {
        expect(source).toContain('AnalyticsPollingBanner');
        expect(source).toContain('Some analytics are temporarily disabled');
        expect(source).toContain(
            'Some account metrics are temporarily disabled',
        );
        expect(source).toContain('Some post metrics are temporarily disabled');
        expect(source).toContain('account metrics temporarily disabled');
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
