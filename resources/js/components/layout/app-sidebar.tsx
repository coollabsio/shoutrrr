import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import SyncPipelinesController from '@/actions/App/Http/Controllers/Settings/SyncPipelinesController';
import AppLogo from '@/components/layout/app-logo';
import { openCommandPalette } from '@/components/layout/command-palette';
import { ModeSwitch } from '@/components/layout/mode-switch';
import { NavUser } from '@/components/layout/nav-user';
import { SidebarContextMenu } from '@/components/layout/sidebar-context-menu';
import { SidebarFooterCard } from '@/components/layout/sidebar-footer-card';
import type { IconComponent } from '@/components/ui/icons';
import {
    Bell,
    Blocks,
    CalendarDays,
    ChartColumn,
    ChevronRight,
    CreditCard,
    Inbox,
    KeyRound,
    ListChecks,
    MessageCircle,
    MessageSquare,
    Pencil,
    Plug,
    RefreshCw,
    Search,
    Settings,
    Share2,
    Shield,
    Sun,
    User,
    Users,
    Wrench,
} from '@/components/ui/icons';
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
    SidebarTrigger,
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
    accountSettingsNavItems,
    type AccountSettingsNavKey,
} from '@/lib/navigation/account-settings-nav';
import {
    composeButtonClassName,
    composeIconClassName,
} from '@/lib/navigation/compose-nav';
import {
    instanceSettingsNavItems,
    type InstanceSettingsNavKey,
} from '@/lib/navigation/instance-settings-nav';
import {
    sidebarContextFor,
    sidebarContextLabel,
    type SidebarContextId,
} from '@/lib/navigation/sidebar-context';
import {
    workspaceSettingsNavItems,
    type WorkspaceSettingsNavKey,
} from '@/lib/navigation/workspace-settings-nav';
import { resolveUiMode } from '@/lib/ui-mode';
import { appVersion, githubReleaseUrl } from '@/lib/version';
import { dashboard } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import { home as agenticHome } from '@/routes/agentic';
import { index as analyticsRoute } from '@/routes/analytics';
import { index as calendarRoute } from '@/routes/calendar';
import { index as engagementRoute } from '@/routes/engagement';
import { index as messagesRoute } from '@/routes/messages';
import { index as postsRoute } from '@/routes/posts';

type NavItem = {
    title: string;
    href: NonNullable<Parameters<typeof Link>[0]['href']>;
    icon: IconComponent;
};

export const workspaceSettingsLabel = 'Workspace';
export const instanceSettingsLabel = 'Instance settings';

const workspaceSettingsIcons: Record<WorkspaceSettingsNavKey, IconComponent> = {
    overview: Settings,
    members: Users,
    apiKeys: KeyRound,
    subscription: CreditCard,
};

const instanceSettingsIcons: Record<InstanceSettingsNavKey, IconComponent> = {
    general: Wrench,
    polling: RefreshCw,
    platforms: Blocks,
    usage: ChartColumn,
    admins: Shield,
};

const accountSettingsIcons: Record<AccountSettingsNavKey, IconComponent> = {
    profile: User,
    security: Shield,
    connections: Plug,
    appearance: Sun,
    notifications: Bell,
};

const versionBadgeClassName =
    'truncate text-[11px] leading-none font-medium text-sidebar-foreground/50 transition-colors hover:text-sidebar-foreground';

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
    { title: 'Messages', href: messagesRoute(), icon: MessageSquare },
];

export function AppSidebar() {
    const page = usePage();
    const {
        auth,
        workspaces,
        features,
        instance,
        shell,
        socialite,
        updateAvailable,
        latestVersion,
        latestReleaseUrl,
    } = page.props;
    const pathname = page.url.split('?')[0];
    const unreadReplies = shell?.unreadReplies ?? 0;
    const unreadMessages = shell?.unreadMessages ?? 0;
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

    const classicMode = resolveUiMode(auth.user?.ui_mode) === 'classic';
    const routeContext = sidebarContextFor(pathname, { classicMode });
    const [menuOverride, setMenuOverride] = useState<
        'main' | SidebarContextId | null
    >(null);

    useEffect(() => {
        setMenuOverride(null);
    }, [pathname]);
    const productHome = classicMode ? dashboard() : agenticHome();
    const composeHref = dashboard();
    const showWorkspaceSettings = workspaces.enabled && workspaces.current;
    const canManageWorkspace = (workspaces.current?.permissions ?? []).includes(
        'workspace.settings.manage',
    );
    const showSyncPipelines = !!showWorkspaceSettings && canManageWorkspace;
    const showInstanceSettings = instance.isOwner;
    const settingsItems = showWorkspaceSettings
        ? workspaceSettingsNavItems({
              permissions: workspaces.current?.permissions ?? [],
              billingEnabled: !!features?.billing,
          })
        : [];
    const instanceItems = showInstanceSettings
        ? instanceSettingsNavItems()
        : [];
    const accountItems = accountSettingsNavItems({
        hasSocialProviders: socialite.providers.length > 0,
    });
    const shownContextId: SidebarContextId | null =
        menuOverride === 'main'
            ? null
            : (menuOverride ?? routeContext?.id ?? null);
    const shownContext =
        shownContextId === null
            ? null
            : {
                  id: shownContextId,
                  label: sidebarContextLabel(shownContextId),
                  backTitle: 'Back to the main menu',
                  postId:
                      shownContextId === 'post'
                          ? (routeContext?.postId ?? null)
                          : null,
              };
    const isItemActive = (
        item: (typeof settingsItems)[number] | (typeof instanceItems)[number],
    ) =>
        item.key === 'overview' || item.key === 'general'
            ? isCurrentUrl(item.href)
            : isCurrentOrParentUrl(item.href);
    const contextItems: Array<NavItem & { key: string; active: boolean }> =
        shownContext?.id === 'workspace'
            ? settingsItems.map((item) => ({
                  key: item.key,
                  title: item.title,
                  href: item.href,
                  icon: workspaceSettingsIcons[item.key],
                  active: isItemActive(item),
              }))
            : shownContext?.id === 'instance'
              ? instanceItems.map((item) => ({
                    key: item.key,
                    title: item.title,
                    href: item.href,
                    icon: instanceSettingsIcons[item.key],
                    active: isItemActive(item),
                }))
              : shownContext?.id === 'account'
                ? accountItems.map((item) => ({
                      key: item.key,
                      title: item.title,
                      href: item.href,
                      icon: accountSettingsIcons[item.key],
                      active: isCurrentOrParentUrl(item.href),
                  }))
                : shownContext?.id === 'post' && shownContext.postId
                  ? [
                        {
                            key: 'edit',
                            title: 'Edit',
                            href: ComposerController.show(shownContext.postId),
                            icon: Pencil,
                            active: isCurrentUrl(
                                ComposerController.show(shownContext.postId),
                            ),
                        },
                        ...(features?.engagement
                            ? [
                                  {
                                      key: 'replies',
                                      title: 'Replies',
                                      href: engagementRoute({
                                          query: {
                                              post: shownContext.postId,
                                          },
                                      }),
                                      icon: MessageCircle,
                                      active: false,
                                  },
                              ]
                            : []),
                    ]
                  : shownContext?.id === 'accounts'
                    ? [
                          {
                              key: 'accounts',
                              title: 'Accounts',
                              href: accountsRoute(),
                              icon: Share2,
                              active: isCurrentUrl(accountsRoute()),
                          },
                      ]
                    : [];

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader className="gap-1.5">
                <SidebarMenu>
                    <SidebarMenuItem className="flex items-center gap-1">
                        <SidebarMenuButton
                            className="h-8 w-auto flex-none group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0!"
                            render={<Link href={productHome} />}
                        >
                            <AppLogo />
                        </SidebarMenuButton>
                        <span className="relative flex min-w-0 group-data-[collapsible=icon]:hidden">
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
                <button
                    type="button"
                    data-chrome-search
                    onClick={openCommandPalette}
                    aria-label="Search"
                    className="flex h-8 w-full items-center justify-between gap-2 rounded-lg bg-black/4 px-2.5 text-[13px] font-medium text-sidebar-foreground/60 transition-colors group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0 hover:bg-black/7 hover:text-sidebar-foreground dark:bg-white/4 dark:hover:bg-white/7"
                >
                    <span className="flex min-w-0 items-center gap-2.5">
                        <Search
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span className="truncate group-data-[collapsible=icon]:hidden">
                            Search
                        </span>
                    </span>
                    <Kbd className="group-data-[collapsible=icon]:hidden">
                        ⌘K
                    </Kbd>
                </button>
            </SidebarHeader>

            <SidebarContent
                className={
                    menuOverride === null
                        ? 'gap-0'
                        : menuOverride === 'main'
                          ? 'nav-swap-back gap-0'
                          : 'nav-swap-forward gap-0'
                }
                key={shownContext?.id ?? 'main'}
                data-sidebar-context={shownContext?.id ?? 'main'}
            >
                <ModeSwitch />
                {shownContext ? (
                    <SidebarContextMenu
                        label={shownContext.label}
                        backTitle={shownContext.backTitle}
                        items={contextItems}
                        onBack={() => setMenuOverride('main')}
                    />
                ) : (
                    <>
                        {!classicMode && (
                            <SidebarGroup>
                                <SidebarGroupContent>
                                    <SidebarMenu>
                                        <SidebarMenuItem>
                                            <SidebarMenuButton
                                                tooltip="Connect accounts"
                                                isActive={isCurrentUrl(
                                                    accountsRoute(),
                                                )}
                                                render={
                                                    <Link
                                                        href={accountsRoute()}
                                                    />
                                                }
                                            >
                                                <Share2 aria-hidden="true" />
                                                <span>Connect accounts</span>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                        {showSyncPipelines && (
                                            <SidebarMenuItem>
                                                <SidebarMenuButton
                                                    tooltip="Sync pipelines"
                                                    isActive={isCurrentOrParentUrl(
                                                        SyncPipelinesController.index()
                                                            .url,
                                                    )}
                                                    render={
                                                        <Link
                                                            href={SyncPipelinesController.index()}
                                                        />
                                                    }
                                                >
                                                    <RefreshCw aria-hidden="true" />
                                                    <span>Sync pipelines</span>
                                                </SidebarMenuButton>
                                            </SidebarMenuItem>
                                        )}
                                    </SidebarMenu>
                                </SidebarGroupContent>
                            </SidebarGroup>
                        )}
                        {classicMode && (
                            <>
                                <SidebarGroup>
                                    <SidebarGroupContent>
                                        <SidebarMenu>
                                            <SidebarMenuItem>
                                                <SidebarMenuButton
                                                    tooltip="Compose new post"
                                                    data-compose=""
                                                    isActive={isCurrentUrl(
                                                        composeHref,
                                                    )}
                                                    className={composeButtonClassName(
                                                        collapsed,
                                                    )}
                                                    render={
                                                        <Link
                                                            href={composeHref}
                                                        />
                                                    }
                                                >
                                                    <span className="pointer-events-none flex items-center gap-2">
                                                        <span
                                                            className={composeIconClassName()}
                                                        >
                                                            <Pencil aria-hidden="true" />
                                                        </span>
                                                        {!collapsed && (
                                                            <span>
                                                                Compose post
                                                            </span>
                                                        )}
                                                    </span>
                                                    {!collapsed && (
                                                        <Kbd className="bg-primary-foreground/15 text-primary-foreground">
                                                            ⌘.
                                                        </Kbd>
                                                    )}
                                                </SidebarMenuButton>
                                            </SidebarMenuItem>
                                            {showSyncPipelines && (
                                                <SidebarMenuItem>
                                                    <SidebarMenuButton
                                                        tooltip="Sync pipelines"
                                                        isActive={isCurrentOrParentUrl(
                                                            SyncPipelinesController.index()
                                                                .url,
                                                        )}
                                                        render={
                                                            <Link
                                                                href={SyncPipelinesController.index()}
                                                            />
                                                        }
                                                    >
                                                        <RefreshCw aria-hidden="true" />
                                                        <span>
                                                            Sync pipelines
                                                        </span>
                                                    </SidebarMenuButton>
                                                </SidebarMenuItem>
                                            )}
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
                                                        (item.title !==
                                                            'Engagement' ||
                                                            features?.engagement) &&
                                                        (item.title !==
                                                            'Messages' ||
                                                            features?.messages),
                                                )
                                                .map((item) => (
                                                    <SidebarMenuItem
                                                        key={item.title}
                                                    >
                                                        <SidebarMenuButton
                                                            tooltip={item.title}
                                                            isActive={isCurrentUrl(
                                                                item.href,
                                                            )}
                                                            render={
                                                                <Link
                                                                    href={
                                                                        item.href
                                                                    }
                                                                />
                                                            }
                                                        >
                                                            <item.icon aria-hidden="true" />
                                                            <span>
                                                                {item.title}
                                                            </span>
                                                            {item.title ===
                                                                'Engagement' &&
                                                            unreadReplies >
                                                                0 ? (
                                                                <span className="ml-auto rounded-full bg-primary-gradient px-1.5 py-0.5 text-[10px] font-medium text-primary-foreground">
                                                                    {unreadReplies >
                                                                    99
                                                                        ? '99+'
                                                                        : unreadReplies}
                                                                </span>
                                                            ) : null}
                                                            {item.title ===
                                                                'Messages' &&
                                                            unreadMessages >
                                                                0 ? (
                                                                <span className="ml-auto rounded-full bg-primary-gradient px-1.5 py-0.5 text-[10px] font-medium text-primary-foreground">
                                                                    {unreadMessages >
                                                                    99
                                                                        ? '99+'
                                                                        : unreadMessages}
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
                                                            <Link
                                                                href={analyticsRoute()}
                                                            />
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
                            </>
                        )}

                        <SidebarGroup>
                            <SidebarGroupLabel>Manage</SidebarGroupLabel>
                            <SidebarGroupContent>
                                <SidebarMenu>
                                    {showWorkspaceSettings && (
                                        <SidebarMenuItem>
                                            <SidebarMenuButton
                                                tooltip={workspaceSettingsLabel}
                                                isActive={
                                                    routeContext?.id ===
                                                    'workspace'
                                                }
                                                onClick={() =>
                                                    setMenuOverride('workspace')
                                                }
                                            >
                                                <Settings aria-hidden="true" />
                                                <span>
                                                    {workspaceSettingsLabel}
                                                </span>
                                                <span className="nav-chevron ml-auto group-data-[collapsible=icon]:hidden">
                                                    <ChevronRight
                                                        className="size-3 opacity-60"
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    )}
                                    {showInstanceSettings && (
                                        <SidebarMenuItem>
                                            <SidebarMenuButton
                                                tooltip={instanceSettingsLabel}
                                                isActive={
                                                    routeContext?.id ===
                                                    'instance'
                                                }
                                                onClick={() =>
                                                    setMenuOverride('instance')
                                                }
                                            >
                                                <Wrench aria-hidden="true" />
                                                <span>
                                                    {instanceSettingsLabel}
                                                </span>
                                                <span className="nav-chevron ml-auto group-data-[collapsible=icon]:hidden">
                                                    <ChevronRight
                                                        className="size-3 opacity-60"
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    )}
                                    <SidebarMenuItem>
                                        <SidebarMenuButton
                                            tooltip="Settings"
                                            isActive={
                                                routeContext?.id === 'account'
                                            }
                                            onClick={() =>
                                                setMenuOverride('account')
                                            }
                                        >
                                            <User aria-hidden="true" />
                                            <span>Settings</span>
                                            <span className="nav-chevron ml-auto group-data-[collapsible=icon]:hidden">
                                                <ChevronRight
                                                    className="size-3 opacity-60"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                </SidebarMenu>
                            </SidebarGroupContent>
                        </SidebarGroup>
                    </>
                )}
            </SidebarContent>

            <SidebarFooter>
                <SidebarFooterCard />
                <div className="flex items-center gap-1 group-data-[collapsible=icon]:flex-col">
                    <div className="min-w-0 flex-1 group-data-[collapsible=icon]:flex-none">
                        <NavUser />
                    </div>
                    <SidebarTrigger className="size-8 shrink-0 text-sidebar-foreground/60 hover:text-sidebar-foreground" />
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
