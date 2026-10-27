import { usePage } from '@inertiajs/react';

import { CustomThemeDialog } from '@/components/layout/appearance-menu';
import { UserInfo } from '@/components/layout/user-info';
import { UserMenuContent } from '@/components/layout/user-menu-content';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ChevronsUpDown } from '@/components/ui/icons';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';

export function NavUser() {
    const { auth } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    if (!auth.user) {
        return null;
    }

    return (
        <>
            <SidebarMenu>
                <SidebarMenuItem>
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={
                                <SidebarMenuButton
                                    size="sm"
                                    className="h-8 rounded-lg px-2 text-xs font-medium data-[popup-open]:bg-black/5 dark:data-[popup-open]:bg-white/6"
                                    data-test="sidebar-menu-button"
                                    aria-label={`Account menu for ${auth.user.name}`}
                                />
                            }
                        >
                            <UserInfo user={auth.user} compact />
                            <ChevronsUpDown className="ml-auto size-3.5 text-neutral-400" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            className="w-52 min-w-0 rounded-lg"
                            sideOffset={8}
                            align="start"
                            side={
                                isMobile
                                    ? 'top'
                                    : state === 'collapsed'
                                      ? 'right'
                                      : 'top'
                            }
                        >
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>
            <CustomThemeDialog />
        </>
    );
}
