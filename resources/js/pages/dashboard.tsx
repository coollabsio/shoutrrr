import { Head, usePage } from '@inertiajs/react';

import Composer from '@/components/compose/composer';
import { DashboardAura } from '@/components/dashboard/dashboard-aura';
import { GettingStartedCard } from '@/components/onboarding/getting-started-card';
import { WelcomeModal } from '@/components/onboarding/welcome-modal';
import { parseDestinationParam } from '@/lib/compose/composer-state';
import { dashboard } from '@/routes';
import type { OnboardingData } from '@/types';

type Props = {
    onboarding: OnboardingData | null;
};

export function shouldShowDashboardPublishingSection(
    accounts: unknown[],
): boolean {
    return accounts.length > 0;
}

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

export default function Dashboard({ onboarding }: Props) {
    const page = usePage();
    const { auth, shell } = page.props;
    const firstName = (auth.user?.name ?? '').split(/\s+/)[0] || 'there';
    const showPublishingSection = shouldShowDashboardPublishingSection(
        shell.accounts,
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

                {showPublishingSection && (
                    <Composer
                        post={null}
                        accounts={shell.accounts}
                        sets={shell.sets}
                        limits={shell.limits}
                        initialScheduleAt={initialScheduleAt}
                        initialDestination={initialDestination}
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
