import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    FileText,
    FolderGit2,
    LayoutGrid,
    ListChecks,
    Settings,
    Share2,
} from 'lucide-react';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { WorkspaceSelector } from '@/components/workspace-selector';
import { dashboard } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import { index as calendarRoute } from '@/routes/calendar';
import { index as postsRoute } from '@/routes/posts';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Posts',
        href: postsRoute(),
        icon: FileText,
    },
    {
        title: 'Calendar',
        href: calendarRoute(),
        icon: CalendarDays,
    },
    {
        title: 'Queue',
        href: PostingScheduleController.show(),
        icon: ListChecks,
    },
    {
        title: 'Accounts',
        href: accountsRoute(),
        icon: Share2,
    },
];

const workspaceSettingsNavItem: NavItem = {
    title: 'Workspace Settings',
    href: WorkspaceSettingsController.showOverview(),
    icon: Settings,
};

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
        target: '_blank',
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
        target: '_blank',
    },
];

export function AppSidebar() {
    const { workspaces } = usePage().props;

    const showWorkspaceSettings = workspaces.enabled && workspaces.current;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <WorkspaceSelector />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter
                    items={
                        showWorkspaceSettings
                            ? [workspaceSettingsNavItem, ...footerNavItems]
                            : footerNavItems
                    }
                    className="mt-auto"
                />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
