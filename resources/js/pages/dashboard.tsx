import { Head, Link, usePage } from '@inertiajs/react';
import { Plug } from 'lucide-react';

import Composer from '@/components/compose/composer';
import { DashboardAura } from '@/components/dashboard/dashboard-aura';
import { GettingStartedCard } from '@/components/onboarding/getting-started-card';
import { WelcomeModal } from '@/components/onboarding/welcome-modal';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { parseDestinationParam } from '@/lib/compose/composer-state';
import {
    shouldShowDashboardNoAccountsNotice,
    shouldShowDashboardPublishingSection,
} from '@/lib/dashboard/accounts';
import { dashboard } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import type { OnboardingData } from '@/types';

type Props = {
    onboarding: OnboardingData | null;
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

function NoAccountsNotice() {
    return (
        <Empty className="min-h-72 bg-card/80 backdrop-blur-sm">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <Plug />
                </EmptyMedia>
                <EmptyTitle>No accounts connected yet</EmptyTitle>
                <EmptyDescription>
                    An admin needs to connect a workspace account before you can
                    compose and publish posts here.
                </EmptyDescription>
            </EmptyHeader>
            <Link
                href={accountsRoute().url}
                className="text-sm font-medium text-primary underline-offset-4 hover:underline"
            >
                View connected accounts
            </Link>
        </Empty>
    );
}

export default function Dashboard({ onboarding }: Props) {
    const page = usePage();
    const { auth, shell, workspaces } = page.props;
    const firstName = (auth.user?.name ?? '').split(/\s+/)[0] || 'there';
    const showPublishingSection = shouldShowDashboardPublishingSection(
        shell.accounts,
    );
    const showNoAccountsNotice = shouldShowDashboardNoAccountsNotice(
        shell.accounts,
        workspaces.current?.permissions ?? [],
    );

    // A calendar slot click opens the composer here with a pre-set schedule time.
    const initialScheduleAt = new URL(
        page.url,
        'http://localhost',
    ).searchParams.get('schedule_at');

    // A command-palette "compose for channel" action navigates here with
    // ?destination=account:<id> or ?destination=set:<id>.
    const initialDestination = parseDestinationParam(
        new URL(page.url, 'http://localhost').searchParams.get('destination'),
    );

    return (
        <>
            <Head title="Dashboard" />
            <div className="relative isolate mx-auto w-full max-w-6xl px-4 pt-6 pb-16 sm:px-6">
                <DashboardAura />
                {onboarding && <WelcomeModal welcomed={onboarding.welcomed} />}
                <h1 className="text-[26px] leading-tight font-semibold tracking-tight">
                    {timeGreeting()},{' '}
                    {/* Brand-green gradient name. Stops are derived from
                        --primary but darkened for light mode (the raw token is
                        too light to read on a white background, and the aura
                        sits behind it) and brightened for dark mode. */}
                    <span className="bg-gradient-to-br from-[color-mix(in_oklch,var(--primary)_70%,black)] to-[color-mix(in_oklch,var(--primary)_48%,black)] bg-clip-text text-transparent dark:from-primary dark:to-[color-mix(in_oklch,var(--primary)_65%,white)]">
                        {firstName}
                    </span>
                </h1>
                <p className="mt-1.5 mb-7 text-[13.5px] tracking-tight text-muted-foreground">
                    Write something new — it autosaves as you go.
                </p>

                {onboarding && <GettingStartedCard onboarding={onboarding} />}

                {showNoAccountsNotice && <NoAccountsNotice />}

                {showPublishingSection && (
                    <Composer
                        post={null}
                        accounts={shell.accounts}
                        sets={shell.sets}
                        limits={shell.limits}
                        initialScheduleAt={initialScheduleAt}
                        initialDestination={initialDestination}
                        autoFocusEditor
                    />
                )}
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
