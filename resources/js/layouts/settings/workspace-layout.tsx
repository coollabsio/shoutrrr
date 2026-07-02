import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import BillingController from '@/actions/App/Http/Controllers/BillingController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

export default function WorkspaceSettingsLayout({
    children,
}: PropsWithChildren) {
    const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
    const { features, workspaces } = usePage().props;

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
        ...(features?.billing
            ? [
                  {
                      title: 'Subscription',
                      href: BillingController.index(),
                      icon: null,
                  },
              ]
            : []),
    ];

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
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted':
                                        item.title === 'Overview'
                                            ? isCurrentUrl(item.href)
                                            : isCurrentOrParentUrl(item.href),
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
