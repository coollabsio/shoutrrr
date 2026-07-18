import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

function read(path: string): string {
    return readFileSync(resolve(process.cwd(), path), 'utf8');
}

const view = read('resources/js/components/posts/published-post-view.tsx');
const page = read('resources/js/pages/compose/index.tsx');

describe('published post view', () => {
    it('wires real engagement numbers and a live permalink, not graphs', () => {
        expect(view).toContain('engagementItems');
        expect(view).toContain('View on ');
        expect(view).toContain('postPermalink');
        // The point of the redesign: numbers, not sparkline charts.
        expect(view).not.toContain('recharts');
        expect(view).not.toContain('Sparkline');
    });

    it('loads metrics as a deferred prop so content renders first', () => {
        expect(view).toContain('data="stats"');
    });

    it('replaces the editor with the published view only once a target is live', () => {
        expect(page).toContain('PublishedPostView');
        expect(page).toContain("t.status === 'published'");
    });

    it('offers a one-shot manual refresh for posts that have aged out of automatic polling', () => {
        expect(view).toContain('PostMetricsRefreshController');
        expect(view).toContain('useHttp');
        expect(view).toContain("only: ['stats']");
    });
});
