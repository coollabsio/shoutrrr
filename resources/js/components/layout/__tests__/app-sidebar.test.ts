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

describe('sidebar page cache policy', () => {
    it('does not prefetch or cache main navigation pages', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/layout/app-sidebar.tsx',
            ),
            'utf8',
        );

        expect(source).not.toContain('prefetch=');
        expect(source).not.toContain('cacheFor=');
    });
});

describe('sidebar app version link', () => {
    it('renders a version badge that opens the current GitHub release', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/layout/app-sidebar.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('githubReleaseUrl');
        expect(source).toContain('appVersion');
        expect(source).toContain('target="_blank"');
        expect(source).toContain('rel="noopener noreferrer"');
    });
});

describe('sidebar footer card + update dot', () => {
    const source = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/layout/app-sidebar.tsx',
        ),
        'utf8',
    );

    it('renders the footer card above the user menu', () => {
        expect(source).toContain('<SidebarFooterCard />');
    });

    it('shows a red update dot on the version badge', () => {
        expect(source).toContain('updateAvailable');
        expect(source).toContain('bg-red-500');
    });
});

describe('version badge update tooltip', () => {
    const source = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/layout/app-sidebar.tsx',
        ),
        'utf8',
    );

    it('links the badge to the new release when an update is available', () => {
        expect(source).toContain('latestReleaseUrl');
    });

    it('names the available version in a tooltip', () => {
        expect(source).toContain('TooltipContent');
        expect(source).toContain('Update available');
        expect(source).toContain('latestVersion');
    });
});

describe('hoisted workspace settings nav', () => {
    const source = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/layout/app-sidebar.tsx',
        ),
        'utf8',
    );

    it('renders workspace settings items from the shared builder', () => {
        expect(source).toContain('workspaceSettingsNavItems(');
        expect(source).toContain('workspaceSettingsIcons[item.key]');
    });
});
