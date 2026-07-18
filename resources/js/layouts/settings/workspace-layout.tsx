import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import Heading from '@/components/common/heading';

export default function WorkspaceSettingsLayout({
    children,
}: PropsWithChildren) {
    const { workspaces } = usePage().props;

    return (
        <div className="mx-auto w-full max-w-2xl px-4 pt-6 pb-16 sm:px-6">
            <Heading
                title="Workspace settings"
                description={
                    workspaces.current
                        ? `Manage ${workspaces.current.name} and its members`
                        : 'Manage your workspace and its members'
                }
            />

            <section className="space-y-12">{children}</section>
        </div>
    );
}
