import type { PropsWithChildren } from 'react';

import Heading from '@/components/common/heading';

/**
 * Account settings pages share one column. Their nav lives in the main
 * sidebar, which swaps to this family while these routes are open.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    return (
        <div className="mx-auto w-full max-w-xl px-4 pt-6 pb-16 sm:px-6">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <section className="space-y-12">{children}</section>
        </div>
    );
}
