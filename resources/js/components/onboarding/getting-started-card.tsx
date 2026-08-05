import { Link, router } from '@inertiajs/react';
import {
    AnimatePresence,
    domAnimation,
    LazyMotion,
    m,
    MotionConfig,
} from 'motion/react';
import { useState, useSyncExternalStore } from 'react';

import { Button } from '@/components/ui/button';
import {
    Check,
    ChevronDown,
    ChevronRight,
    Circle,
    Clock,
    PenLine,
    Radio,
    UserPlus,
    X,
} from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import {
    dismiss as dismissRoute,
    step as stepRoute,
} from '@/routes/onboarding';
import type { OnboardingData } from '@/types';

type StepIconComponent = React.ComponentType<{ className?: string }>;

const STEP_ICONS: Record<string, StepIconComponent> = {
    connect_account: Radio,
    first_post: PenLine,
    timezone: Clock,
    invite_teammate: UserPlus,
};

// Collapse state persists across visits in localStorage, mirroring how the
// theme preference is stored. useSyncExternalStore keeps it SSR-safe (server
// renders expanded, the client reconciles on hydration).
const COLLAPSE_KEY = 'onboarding-checklist-collapsed';
const collapseListeners = new Set<() => void>();

function subscribeCollapsed(callback: () => void): () => void {
    collapseListeners.add(callback);
    return () => collapseListeners.delete(callback);
}

function getCollapsedSnapshot(): boolean {
    return (
        typeof window !== 'undefined' &&
        localStorage.getItem(COLLAPSE_KEY) === '1'
    );
}

function setCollapsed(value: boolean): void {
    if (typeof window === 'undefined') {
        return;
    }
    localStorage.setItem(COLLAPSE_KEY, value ? '1' : '0');
    collapseListeners.forEach((listener) => listener());
}

export function canDismissOnboarding(onboarding: OnboardingData): boolean {
    return (
        onboarding.steps.find((step) => step.key === 'connect_account')?.done ??
        false
    );
}

export function GettingStartedCard({
    onboarding,
}: {
    onboarding: OnboardingData;
}) {
    const [hidden, setHidden] = useState(false);
    const collapsed = useSyncExternalStore(
        subscribeCollapsed,
        getCollapsedSnapshot,
        () => false,
    );

    if (
        hidden ||
        onboarding.dismissed ||
        onboarding.complete ||
        onboarding.steps.length === 0
    ) {
        return null;
    }

    function dismiss() {
        setHidden(true);
        router.post(
            dismissRoute().url,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    const done = onboarding.steps.filter((s) => s.done).length;
    const total = onboarding.steps.length;
    const nextKey = onboarding.steps.find((s) => !s.done)?.key;
    const canDismiss = canDismissOnboarding(onboarding);

    return (
        <section className="mb-7 rounded-xl border bg-card p-5 shadow-xs">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold">Finish setting up</h2>
                    <p className="text-[13px] text-muted-foreground">
                        A few steps to get the most out of your workspace.
                    </p>
                </div>
                <div className="flex items-center gap-1">
                    <span className="mr-1 text-xs text-muted-foreground tabular-nums">
                        {done}/{total}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => setCollapsed(!collapsed)}
                        aria-expanded={!collapsed}
                        aria-label={
                            collapsed
                                ? 'Expand checklist'
                                : 'Collapse checklist'
                        }
                    >
                        <ChevronDown
                            className={cn(
                                'size-4 transition-transform duration-200',
                                collapsed && '-rotate-90',
                            )}
                        />
                    </Button>
                    {canDismiss && (
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={dismiss}
                            aria-label="Dismiss"
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
            </div>

            {/* Progress bar stays visible in both collapsed and expanded states. */}
            <div
                className="mt-4 h-1.5 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuenow={done}
                aria-valuemin={0}
                aria-valuemax={total}
            >
                <div
                    className="h-full rounded-full bg-primary transition-[width] duration-500"
                    style={{ width: `${total ? (done / total) * 100 : 0}%` }}
                />
            </div>

            <LazyMotion features={domAnimation}>
                <MotionConfig reducedMotion="user">
                    <AnimatePresence initial={false}>
                        {!collapsed && (
                            <m.div
                                key="body"
                                initial={{ height: 0, opacity: 0 }}
                                animate={{ height: 'auto', opacity: 1 }}
                                exit={{ height: 0, opacity: 0 }}
                                transition={{ duration: 0.2, ease: 'easeOut' }}
                                className="overflow-hidden"
                            >
                                <div className="mt-3">
                                    <ul className="flex flex-col gap-1">
                                        {onboarding.steps.map((step) => {
                                            const StepIcon =
                                                STEP_ICONS[step.key] ?? Circle;
                                            const isNext = step.key === nextKey;

                                            return (
                                                <li key={step.key}>
                                                    <Link
                                                        href={
                                                            step.clickToComplete
                                                                ? stepRoute()
                                                                      .url
                                                                : step.href
                                                        }
                                                        method={
                                                            step.clickToComplete
                                                                ? 'post'
                                                                : 'get'
                                                        }
                                                        data={
                                                            step.clickToComplete
                                                                ? {
                                                                      key: step.key,
                                                                  }
                                                                : undefined
                                                        }
                                                        as={
                                                            step.clickToComplete
                                                                ? 'button'
                                                                : 'a'
                                                        }
                                                        className={cn(
                                                            'flex w-full items-center gap-3 rounded-md px-2 py-2 text-left text-sm transition-colors hover:bg-accent',
                                                            step.done &&
                                                                'text-muted-foreground',
                                                        )}
                                                    >
                                                        <span
                                                            className={cn(
                                                                'flex size-5 shrink-0 items-center justify-center rounded-full border',
                                                                step.done
                                                                    ? 'border-(--primary-gradient-edge) bg-primary-gradient text-primary-foreground'
                                                                    : 'border-muted-foreground/40',
                                                            )}
                                                        >
                                                            {step.done ? (
                                                                <Check className="size-3.5" />
                                                            ) : (
                                                                <StepIcon className="size-3.5 text-muted-foreground" />
                                                            )}
                                                        </span>
                                                        <span
                                                            className={cn(
                                                                step.done &&
                                                                    'line-through',
                                                            )}
                                                        >
                                                            {step.label}
                                                        </span>
                                                        {isNext && (
                                                            <ChevronRight className="ml-auto size-4 text-primary" />
                                                        )}
                                                    </Link>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            </m.div>
                        )}
                    </AnimatePresence>
                </MotionConfig>
            </LazyMotion>
        </section>
    );
}
