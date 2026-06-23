import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { instanceSettingsLabel, workspaceSettingsLabel } from '../app-sidebar';

describe('workspaceSettingsLabel', () => {
    it('identifies the sidebar destination as workspace settings', () => {
        expect(workspaceSettingsLabel).toBe('Workspace settings');
    });

    it('identifies the owner-only instance settings destination', () => {
        expect(instanceSettingsLabel).toBe('Instance settings');
    });
});

describe('settings sidebar active states', () => {
    it('keeps instance settings active on child pages', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/layout/app-sidebar.tsx',
            ),
            'utf8',
        );

        expect(source).toContain(
            'isActive={isCurrentOrParentUrl(\n                                                InstanceSettingsController.edit(),\n                                            )}',
        );
    });
});

describe('sidebar nav click targets', () => {
    it('lets sidebar links receive clicks from their SVG icons', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/ui/sidebar.tsx'),
            'utf8',
        );

        expect(source).toContain('[&_svg]:pointer-events-none');
    });

    it('keeps collapsed invisible group labels from covering nearby icons', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/ui/sidebar.tsx'),
            'utf8',
        );

        expect(source).toContain(
            'group-data-[collapsible=icon]:pointer-events-none group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0',
        );
    });
});
