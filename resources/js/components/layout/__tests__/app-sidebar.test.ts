import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { instanceSettingsLabel, workspaceSettingsLabel } from '../app-sidebar';

describe('workspaceSettingsLabel', () => {
    it('labels workspace settings as Workspace', () => {
        expect(workspaceSettingsLabel).toBe('Workspace');
    });

    it('identifies the owner-only instance settings destination', () => {
        expect(instanceSettingsLabel).toBe('Instance settings');
    });
});

describe('settings sidebar active states', () => {
    it('marks each settings destination active directly', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/layout/app-sidebar.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('instanceSettingsNavItems(');
        expect(source).toContain('isActive={isItemActive(item)}');
        expect(source).toContain("item.key === 'general'");
        expect(source).not.toContain('settingsActive');
        expect(source).not.toContain('instanceActive');
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

describe('settings navbar items', () => {
    const source = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/layout/app-sidebar.tsx',
        ),
        'utf8',
    );

    it('renders every workspace settings item directly in the navbar', () => {
        expect(source).toContain('workspaceSettingsNavItems(');
        expect(source).toContain('workspaceSettingsIcons');
        expect(source).toContain('workspaceSettingsLabel');
        expect(source).toContain('settingsItems.map((item)');
    });

    it('renders every instance settings item directly in the navbar', () => {
        expect(source).toContain('instanceSettingsNavItems(');
        expect(source).toContain('instanceSettingsIcons');
        expect(source).toContain('instanceSettingsLabel');
        expect(source).toContain('instanceItems.map((item)');
    });

    it('does not hide settings items behind collapsibles or dropdowns', () => {
        expect(source).not.toContain('NestedSidebarNav');
        expect(source).not.toContain('<Collapsible');
        expect(source).not.toContain('<DropdownMenu>');
    });
});
