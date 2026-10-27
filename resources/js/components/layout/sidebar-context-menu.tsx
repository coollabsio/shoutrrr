import { Link } from '@inertiajs/react';

import type { IconComponent } from '@/components/ui/icons';
import { ChevronLeft } from '@/components/ui/icons';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

type ContextItem = {
    key: string;
    title: string;
    href: NonNullable<Parameters<typeof Link>[0]['href']>;
    icon: IconComponent;
    active: boolean;
};

/**
 * Replaces the main sidebar menu. Back reveals the main menu without leaving
 * the page, the same swap Coolify uses for a resource or settings family.
 */
export function SidebarContextMenu({
    label,
    backTitle,
    items,
    onBack,
}: {
    label: string;
    backTitle: string;
    items: ContextItem[];
    onBack: () => void;
}) {
    return (
        <>
            <SidebarGroup className="border-b border-sidebar-border">
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                tooltip={backTitle}
                                aria-label={backTitle}
                                data-test="sidebar-context-back"
                                onClick={onBack}
                            >
                                <ChevronLeft aria-hidden="true" />
                                <span>{label}</span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
            <SidebarGroup>
                <SidebarGroupContent>
                    <SidebarMenu>
                        {items.map((item) => (
                            <SidebarMenuItem key={item.key}>
                                <SidebarMenuButton
                                    tooltip={item.title}
                                    isActive={item.active}
                                    render={<Link href={item.href} />}
                                >
                                    <item.icon aria-hidden="true" />
                                    <span>{item.title}</span>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
        </>
    );
}
