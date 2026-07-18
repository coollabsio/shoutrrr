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
        expect(app).toContain("name === 'settings/instance-polling' ||");
        expect(app).toContain("name === 'settings/instance-platforms' ||");
        expect(app).toContain("name === 'settings/instance-admins':");
        expect(app).toContain('return [AppLayout, InstanceSettingsLayout];');
    });

    it('does not appear in the workspace settings layout', () => {
        const workspaceLayout = readSource(
            'resources/js/layouts/settings/workspace-layout.tsx',
        );

        expect(workspaceLayout).not.toContain('InstanceSettingsController');
        expect(workspaceLayout).not.toContain("title: 'Instance'");
        expect(workspaceLayout).not.toContain('workspaceSettingsNavItems');
        expect(workspaceLayout).not.toContain(
            'aria-label="Workspace settings"',
        );
    });

    it('does not render a page-level sub navigation', () => {
        const layout = readSource(
            'resources/js/layouts/settings/instance-layout.tsx',
        );

        expect(layout).not.toContain('InstanceSettingsController');
        expect(layout).not.toContain("title: 'Polling'");
        expect(layout).not.toContain('aria-label="Instance settings"');
        expect(layout).toContain('title="Instance settings"');
    });

    it('centers content in a single column without a side nav gutter', () => {
        const instanceLayout = readSource(
            'resources/js/layouts/settings/instance-layout.tsx',
        );
        const workspaceLayout = readSource(
            'resources/js/layouts/settings/workspace-layout.tsx',
        );

        expect(instanceLayout).toContain('mx-auto w-full max-w-4xl');
        expect(workspaceLayout).toContain('mx-auto w-full max-w-2xl');
        expect(instanceLayout).not.toContain('lg:flex-row');
        expect(workspaceLayout).not.toContain('lg:flex-row');
    });

    it('breadcrumbs instance settings as a top-level settings area', () => {
        const page = readSource('resources/js/pages/settings/instance.tsx');

        expect(page).toContain("title: 'Instance settings'");
        expect(page).not.toContain("title: 'Workspace settings'");
    });
});
