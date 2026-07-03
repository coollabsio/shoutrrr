import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
    resolve(process.cwd(), 'resources/js/pages/settings/instance-polling.tsx'),
    'utf8',
);

describe('instance polling settings', () => {
    it('autosaves platform toggles separately from interval edits', () => {
        expect(source).toContain('function setPlatformEnabled');
        expect(source).toContain('router.put');
        expect(source).toContain('onEnabledChange={setPlatformEnabled}');
        expect(source).toContain('onChange={setMinutes}');
    });
});
