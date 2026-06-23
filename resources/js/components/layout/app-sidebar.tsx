import { Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ChartColumn,
    Inbox,
    ListChecks,
    Pencil,
    Settings,
    Share2,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useEffect } from 'react';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import AppLogo from '@/components/layout/app-logo';
import { NavUser } from '@/components/layout/nav-user';
import { Kbd } from '@/components/ui/kbd';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { WorkspaceSelector } from '@/components/workspace/workspace-selector';
import { useCurrentUrl } from '@/hooks/use-current-url';
import {
    composeButtonClassName,
    composeIconClassName,
} from '@/lib/navigation/compose-nav';
import { dashboard } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import { index as analyticsRoute } from '@/routes/analytics';
import { index as calendarRoute } from '@/routes/calendar';
import { index as postsRoute } from '@/routes/posts';

type NavItem = {
    title: string;
    href: NonNullable<Parameters<typeof Link>[0]['href']>;
    icon: LucideIcon;
};

export const workspaceSettingsLabel = 'Workspace settings';
export const instanceSettingsLabel = 'Instance settings';

const postsNavItems: NavItem[] = [
    { title: 'Posts', href: postsRoute(), icon: Inbox },
    { title: 'Calendar', href: calendarRoute(), icon: CalendarDays },
    {
        title: 'Queue',
        href: PostingScheduleController.show(),
        icon: ListChecks,
    },
    { title: 'Accounts', href: accountsRoute(), icon: Share2 },
];

export function AppSidebar() {
    const { workspaces, features, instance } = usePage().props;
    const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
    const { state, setOpenMobile } = useSidebar();
    const collapsed = state === 'collapsed';

    // The mobile sidebar is an off-canvas sheet living in a persistent layout,
    // so it stays open across Inertia visits. Close it the moment a real
    // navigation starts (link click, command palette, programmatic visit).
    // Prefetch visits also fire `start`; ignore those, otherwise the sheet
    // self-closes the instant its prefetching nav links mount.
    useEffect(
        () =>
            router.on('start', (event) => {
                if (!event.detail.visit.prefetch) {
                    setOpenMobile(false);
                }
            }),
        [setOpenMobile],
    );

    const composeHref = dashboard();
    const showWorkspaceSettings = workspaces.enabled && workspaces.current;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="gap-1.5">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild className="h-8">
                            <Link
                                href={composeHref}
                                prefetch={['mount', 'hover']}
                                cacheFor={['30s', '1m']}
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <WorkspaceSelector />
            </SidebarHeader>

            <SidebarContent className="gap-0">
                <SidebarGroup className="border-b border-sidebar-border">
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    tooltip="Compose new post"
                                    isActive={isCurrentUrl(composeHref)}
                                    className={composeButtonClassName(
                                        collapsed,
                                    )}
                                >
                                    <Link
                                        href={composeHref}
                                        prefetch={['mount', 'hover']}
                                        cacheFor={['30s', '1m']}
                                    >
                                        <span className="pointer-events-none flex items-center gap-2">
                                            <span
                                                className={composeIconClassName()}
                                            >
                                                <Pencil aria-hidden="true" />
                                            </span>
                                            {!collapsed && (
                                                <span>Compose post</span>
                                            )}
                                        </span>
                                        {!collapsed && (
                                            <Kbd className="bg-primary-foreground/15 text-primary-foreground">
                                                ⌘.
                                            </Kbd>
                                        )}
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                <SidebarGroup>
                    <SidebarGroupLabel>Posts</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {postsNavItems.map((item) => {
                                const cacheFor: [string, string] =
                                    item.title === 'Calendar' ||
                                    item.title === 'Queue'
                                        ? ['10s', '30s']
                                        : ['30s', '1m'];
                                return (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton
                                            asChild
                                            tooltip={item.title}
                                            isActive={isCurrentUrl(item.href)}
                                        >
                                            <Link
                                                href={item.href}
                                                prefetch={['mount', 'hover']}
                                                cacheFor={cacheFor}
                                            >
                                                <item.icon aria-hidden="true" />
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                );
                            })}
                            {features?.analytics && (
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        tooltip="Analytics"
                                        isActive={isCurrentUrl(
                                            analyticsRoute(),
                                        )}
                                    >
                                        <Link
                                            href={analyticsRoute()}
                                            prefetch={['mount', 'hover']}
                                            cacheFor={['30s', '1m']}
                                        >
                                            <ChartColumn aria-hidden="true" />
                                            <span>Analytics</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            )}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                {showWorkspaceSettings && (
                    <SidebarGroup>
                        <SidebarGroupLabel>Workspace</SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        tooltip={workspaceSettingsLabel}
                                        isActive={isCurrentOrParentUrl(
                                            WorkspaceSettingsController.showOverview(),
                                        )}
                                    >
                                        <Link
                                            href={WorkspaceSettingsController.showOverview()}
                                            prefetch={['mount', 'hover']}
                                            cacheFor={['30s', '1m']}
                                        >
                                            <Settings aria-hidden="true" />
                                            <span>
                                                {workspaceSettingsLabel}
                                            </span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                {instance.isOwner && (
                                    <SidebarMenuItem>
                                        <SidebarMenuButton
                                            asChild
                                            tooltip={instanceSettingsLabel}
                                            isActive={isCurrentOrParentUrl(
                                                InstanceSettingsController.edit(),
                                            )}
                                        >
                                            <Link
                                                href={InstanceSettingsController.edit()}
                                                prefetch={['mount', 'hover']}
                                                cacheFor={['30s', '1m']}
                                            >
                                                <Wrench aria-hidden="true" />
                                                <span>
                                                    {instanceSettingsLabel}
                                                </span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                )}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
