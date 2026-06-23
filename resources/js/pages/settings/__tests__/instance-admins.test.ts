import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readSource = (file: string) =>
    readFileSync(resolve(process.cwd(), file), 'utf8');

describe('instance admins page', () => {
    it('uses the workspace member removal interaction style', () => {
        const page = readSource(
            'resources/js/pages/settings/instance-admins.tsx',
        );

        expect(page).toContain('DropdownMenu');
        expect(page).toContain('RemoveInstanceOwnerDialog');
        expect(page).not.toContain('useConfirm');
    });
});
