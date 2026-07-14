import { Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ChartColumn,
    CreditCard,
    Inbox,
    KeyRound,
    ListChecks,
    MessageCircle,
    Pencil,
    Settings,
    Share2,
    Users,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useEffect } from 'react';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import AppLogo from '@/components/layout/app-logo';
import { NavUser } from '@/components/layout/nav-user';
import { SidebarFooterCard } from '@/components/layout/sidebar-footer-card';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { WorkspaceSelector } from '@/components/workspace/workspace-selector';
import { useCurrentUrl } from '@/hooks/use-current-url';
import {
    composeButtonClassName,
    composeIconClassName,
} from '@/lib/navigation/compose-nav';
import {
    workspaceSettingsNavItems,
    type WorkspaceSettingsNavKey,
} from '@/lib/navigation/workspace-settings-nav';
import { appVersion, githubReleaseUrl } from '@/lib/version';
import { dashboard } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import { index as analyticsRoute } from '@/routes/analytics';
import { index as calendarRoute } from '@/routes/calendar';
import { index as engagementRoute } from '@/routes/engagement';
import { index as postsRoute } from '@/routes/posts';

type NavItem = {
    title: string;
    href: NonNullable<Parameters<typeof Link>[0]['href']>;
    icon: LucideIcon;
};

export const workspaceSettingsLabel = 'Workspace settings';
export const instanceSettingsLabel = 'Instance settings';

const versionBadgeClassName =
    'rounded-full border border-sidebar-border px-1.5 py-0.5 text-[10px] leading-none font-medium text-sidebar-foreground/60 transition-colors hover:border-sidebar-accent-foreground/30 hover:text-sidebar-foreground';

const postsNavItems: NavItem[] = [
    { title: 'Posts', href: postsRoute(), icon: Inbox },
    { title: 'Calendar', href: calendarRoute(), icon: CalendarDays },
    {
        title: 'Queue',
        href: PostingScheduleController.show(),
        icon: ListChecks,
    },
    { title: 'Accounts', href: accountsRoute(), icon: Share2 },
    { title: 'Engagement', href: engagementRoute(), icon: MessageCircle },
];

const workspaceSettingsIcons: Record<WorkspaceSettingsNavKey, LucideIcon> = {
    overview: Settings,
    members: Users,
    apiKeys: KeyRound,
    subscription: CreditCard,
};

export function AppSidebar() {
    const {
        workspaces,
        features,
        instance,
        shell,
        updateAvailable,
        latestVersion,
        latestReleaseUrl,
    } = usePage().props;
    const unreadReplies = shell?.unreadReplies ?? 0;
    const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
    const { state, setOpenMobile } = useSidebar();
    const collapsed = state === 'collapsed';

    // The mobile sidebar is an off-canvas sheet living in a persistent layout,
    // so it stays open across Inertia visits. Close it the moment a real
    // navigation starts (link click, command palette, programmatic visit).
    useEffect(
        () =>
            router.on('start', () => {
                setOpenMobile(false);
            }),
        [setOpenMobile],
    );

    const composeHref = dashboard();
    const showWorkspaceSettings = workspaces.enabled && workspaces.current;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="gap-1.5">
                <SidebarMenu>
                    <SidebarMenuItem className="flex items-center gap-1">
                        <SidebarMenuButton
                            className="h-8 min-w-0 flex-1 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0!"
                            render={<Link href={composeHref} />}
                        >
                            <AppLogo />
                        </SidebarMenuButton>
                        <span className="relative flex group-data-[collapsible=icon]:hidden">
                            {(() => {
                                const badge = (
                                    <a
                                        href={
                                            updateAvailable && latestReleaseUrl
                                                ? latestReleaseUrl
                                                : githubReleaseUrl
                                        }
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className={versionBadgeClassName}
                                        aria-label={
                                            updateAvailable
                                                ? `Shoutrrr ${appVersion} — update ${latestVersion ?? ''} available on GitHub`
                                                : `View Shoutrrr ${appVersion} release notes on GitHub`
                                        }
                                    >
                                        {appVersion}
                                    </a>
                                );

                                return updateAvailable ? (
                                    <Tooltip>
                                        <TooltipTrigger render={badge} />
                                        <TooltipContent>
                                            Update available: {latestVersion}
                                        </TooltipContent>
                                    </Tooltip>
                                ) : (
                                    badge
                                );
                            })()}
                            {updateAvailable && (
                                <span
                                    className="absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-sidebar"
                                    aria-hidden="true"
                                />
                            )}
                        </span>
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
                                    tooltip="Compose new post"
                                    isActive={isCurrentUrl(composeHref)}
                                    className={composeButtonClassName(
                                        collapsed,
                                    )}
                                    render={<Link href={composeHref} />}
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
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                <SidebarGroup>
                    <SidebarGroupLabel>Posts</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {postsNavItems
                                .filter(
                                    (item) =>
                                        item.title !== 'Engagement' ||
                                        features?.engagement,
                                )
                                .map((item) => (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton
                                            tooltip={item.title}
                                            isActive={isCurrentUrl(item.href)}
                                            render={<Link href={item.href} />}
                                        >
                                            <item.icon aria-hidden="true" />
                                            <span>{item.title}</span>
                                            {item.title === 'Engagement' &&
                                            unreadReplies > 0 ? (
                                                <span className="ml-auto rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-medium text-primary-foreground">
                                                    {unreadReplies > 99
                                                        ? '99+'
                                                        : unreadReplies}
                                                </span>
                                            ) : null}
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                ))}
                            {features?.analytics && (
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        tooltip="Analytics"
                                        isActive={isCurrentUrl(
                                            analyticsRoute(),
                                        )}
                                        render={
                                            <Link href={analyticsRoute()} />
                                        }
                                    >
                                        <ChartColumn aria-hidden="true" />
                                        <span>Analytics</span>
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
                                {workspaceSettingsNavItems({
                                    permissions:
                                        workspaces.current?.permissions ?? [],
                                    billingEnabled: !!features?.billing,
                                }).map((item) => {
                                    const Icon =
                                        workspaceSettingsIcons[item.key];
                                    const active =
                                        item.key === 'overview'
                                            ? isCurrentUrl(item.href)
                                            : isCurrentOrParentUrl(item.href);

                                    return (
                                        <SidebarMenuItem key={item.key}>
                                            <SidebarMenuButton
                                                tooltip={item.title}
                                                isActive={active}
                                                render={
                                                    <Link href={item.href} />
                                                }
                                            >
                                                <Icon aria-hidden="true" />
                                                <span>{item.title}</span>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    );
                                })}
                                {instance.isOwner && (
                                    <SidebarMenuItem>
                                        <SidebarMenuButton
                                            tooltip={instanceSettingsLabel}
                                            isActive={isCurrentOrParentUrl(
                                                InstanceSettingsController.edit(),
                                            )}
                                            render={
                                                <Link
                                                    href={InstanceSettingsController.edit()}
                                                />
                                            }
                                        >
                                            <Wrench aria-hidden="true" />
                                            <span>{instanceSettingsLabel}</span>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                )}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                <SidebarFooterCard />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
