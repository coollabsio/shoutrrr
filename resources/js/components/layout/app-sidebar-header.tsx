import { Search } from 'lucide-react';

import { ThemeToggle } from '@/components/common/theme-toggle';
import { Breadcrumbs } from '@/components/layout/breadcrumbs';
import { openCommandPalette } from '@/components/layout/command-palette';
import { NotificationBell } from '@/components/notifications/notification-bell';
import { Kbd } from '@/components/ui/kbd';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useNotificationsPoll } from '@/hooks/notifications/use-notifications-poll';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    useNotificationsPoll();

    return (
        <header className="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 bg-background/85 px-6 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <div className="ml-auto flex items-center gap-1.5">
                <button
                    type="button"
                    onClick={openCommandPalette}
                    className="hidden h-8 items-center gap-2 rounded-lg border border-input bg-input/40 pr-1.5 pl-2.5 text-sm text-muted-foreground transition-colors hover:bg-input/70 sm:flex"
                >
                    <Search className="size-3.5" />
                    <span className="pr-6">Search…</span>
                    <Kbd>⌘K</Kbd>
                </button>
                <button
                    type="button"
                    onClick={openCommandPalette}
                    aria-label="Search"
                    className="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground sm:hidden"
                >
                    <Search className="size-4" />
                </button>
                <NotificationBell />
                <ThemeToggle />
            </div>
        </header>
    );
}
