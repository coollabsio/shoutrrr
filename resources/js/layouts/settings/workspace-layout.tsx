import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { workspaceSettingsNavItems } from '@/lib/navigation/workspace-settings-nav';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

export default function WorkspaceSettingsLayout({
    children,
}: PropsWithChildren) {
    const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
    const { features, workspaces } = usePage().props;
    const workspacePermissions = workspaces.current?.permissions ?? [];

    const sidebarNavItems: NavItem[] = workspaceSettingsNavItems({
        permissions: workspacePermissions,
        billingEnabled: !!features?.billing,
    }).map((item) => ({ title: item.title, href: item.href, icon: null }));

    return (
        <div className="mx-auto w-full max-w-6xl px-4 pt-6 pb-16 sm:px-6">
            <Heading
                title="Workspace settings"
                description={
                    workspaces.current
                        ? `Manage ${workspaces.current.name} and its members`
                        : 'Manage your workspace and its members'
                }
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Workspace settings"
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                nativeButton={false}
                                className={cn('w-full justify-start', {
                                    'bg-muted':
                                        item.title === 'Overview'
                                            ? isCurrentUrl(item.href)
                                            : isCurrentOrParentUrl(item.href),
                                })}
                                render={<Link href={item.href} />}
                            >
                                {item.icon && <item.icon className="h-4 w-4" />}
                                {item.title}
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
