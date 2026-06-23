import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readSource = (file: string) =>
    readFileSync(resolve(process.cwd(), file), 'utf8');

describe('instance settings layout', () => {
    it('uses its own layout instead of the workspace settings layout', () => {
        const app = readSource('resources/js/app.tsx');

        expect(app).toContain(
            "import InstanceSettingsLayout from '@/layouts/settings/instance-layout';",
        );
        expect(app).toContain("case name === 'settings/instance' ||");
        expect(app).toContain("name === 'settings/instance-admins':");
        expect(app).toContain('return [AppLayout, InstanceSettingsLayout];');
    });

    it('does not appear in the workspace settings sub navigation', () => {
        const workspaceLayout = readSource(
            'resources/js/layouts/settings/workspace-layout.tsx',
        );

        expect(workspaceLayout).not.toContain('InstanceSettingsController');
        expect(workspaceLayout).not.toContain("title: 'Instance'");
    });

    it('includes an admins sub navigation item', () => {
        const layout = readSource(
            'resources/js/layouts/settings/instance-layout.tsx',
        );

        expect(layout).toContain("title: 'Admins'");
        expect(layout).toContain('InstanceSettingsController.admins()');
    });

    it('breadcrumbs instance settings as a top-level settings area', () => {
        const page = readSource('resources/js/pages/settings/instance.tsx');

        expect(page).toContain("title: 'Instance settings'");
        expect(page).not.toContain("title: 'Workspace settings'");
    });
});
