import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = () =>
    readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/queue/add-time-popover.tsx',
        ),
        'utf8',
    );

describe('posting queue add-time popover', () => {
    it('keeps time select triggers wide enough for two-digit values and the chevron', () => {
        const triggerClass =
            'className="h-7 w-16 px-2 font-mono text-[12px] tabular-nums"';

        expect(source().split(triggerClass)).toHaveLength(3);
        expect(source()).not.toContain('className="h-7 w-14 font-mono');
    });
});
