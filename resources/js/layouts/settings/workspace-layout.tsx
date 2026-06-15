import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Overview',
        href: WorkspaceSettingsController.showOverview(),
        icon: null,
    },
    {
        title: 'Members',
        href: WorkspaceSettingsController.showMembers(),
        icon: null,
    },
];

export default function WorkspaceSettingsLayout({
    children,
}: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();
    const { workspaces } = usePage().props;

    return (
        <div className="px-4 py-6">
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
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
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
