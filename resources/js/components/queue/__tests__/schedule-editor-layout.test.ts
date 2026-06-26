import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = () =>
    readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/queue/schedule-editor.tsx',
        ),
        'utf8',
    );

describe('posting queue schedule editor layout', () => {
    it('renders save bars below quick adds and below the week board', () => {
        const component = source();

        const quickAddIndex = component.indexOf('<QuickAddToolbar');
        const saveBarIndex = component.indexOf('<SaveBar');
        const weekBoardIndex = component.indexOf('{/* Week board */}');
        const bottomSaveBarIndex = component.indexOf(
            '<SaveBar',
            saveBarIndex + 1,
        );

        expect(quickAddIndex).toBeGreaterThan(-1);
        expect(saveBarIndex).toBeGreaterThan(quickAddIndex);
        expect(saveBarIndex).toBeLessThan(weekBoardIndex);
        expect(bottomSaveBarIndex).toBeGreaterThan(weekBoardIndex);
        expect(component.match(/<SaveBar/g)).toHaveLength(2);
    });
});
