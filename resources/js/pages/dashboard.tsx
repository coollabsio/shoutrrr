import { Head, usePage } from '@inertiajs/react';

import { RecentFeed } from '@/components/recent-feed';
import Composer from '@/pages/compose/Composer';
import type {
    Account,
    AccountSet,
    PlatformLimits,
} from '@/pages/compose/types';
import type { PostRowData } from '@/pages/posts/post-row';
import { dashboard } from '@/routes';

type Props = {
    accounts: Account[];
    sets: AccountSet[];
    limits: PlatformLimits[];
    posts: PostRowData[];
};

function timeGreeting(): string {
    const hour = new Date().getHours();
    if (hour < 5) {
        return 'Working late';
    }
    if (hour < 12) {
        return 'Good morning';
    }
    if (hour < 18) {
        return 'Good afternoon';
    }

    return 'Good evening';
}

export default function Dashboard({ accounts, sets, limits, posts }: Props) {
    const { auth } = usePage().props;
    const firstName = (auth.user?.name ?? '').split(/\s+/)[0] || 'there';

    return (
        <>
            <Head title="Dashboard" />
            <div className="mx-auto w-full max-w-[880px] px-4 pt-8 pb-16 sm:px-6 sm:pt-12">
                <h1 className="text-[26px] leading-tight font-semibold tracking-tight">
                    {timeGreeting()},{' '}
                    <span className="bg-gradient-to-br from-primary to-primary/60 bg-clip-text text-transparent">
                        {firstName}
                    </span>
                </h1>
                <p className="mt-1.5 mb-7 text-[13.5px] tracking-tight text-muted-foreground">
                    Write something new — it autosaves as you go.
                </p>

                <Composer
                    post={null}
                    accounts={accounts}
                    sets={sets}
                    limits={limits}
                />

                <RecentFeed posts={posts} />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
