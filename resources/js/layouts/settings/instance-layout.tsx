import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

export default function InstanceSettingsLayout({
    children,
}: PropsWithChildren) {
    const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();

    const sidebarNavItems: NavItem[] = [
        {
            title: 'General',
            href: InstanceSettingsController.edit(),
            icon: null,
        },
        {
            title: 'Polling',
            href: InstanceSettingsController.polling(),
            icon: null,
        },
        {
            title: 'Platforms',
            href: InstanceSettingsController.platforms(),
            icon: null,
        },
        {
            title: 'Usage',
            href: InstanceSettingsController.usage(),
            icon: null,
        },
        {
            title: 'Admins',
            href: InstanceSettingsController.admins(),
            icon: null,
        },
    ];

    return (
        <div className="mx-auto w-full max-w-6xl px-4 pt-6 pb-16 sm:px-6">
            <Heading
                title="Instance settings"
                description="Manage settings that affect every user on this self-hosted instance"
            />

            <div className="flex min-w-0 flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl shrink-0 lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Instance settings"
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                nativeButton={false}
                                className={cn('w-full justify-start', {
                                    'bg-muted':
                                        item.title === 'General'
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

                <div className="min-w-0 flex-1 md:max-w-4xl">
                    <section className="max-w-4xl min-w-0 space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
