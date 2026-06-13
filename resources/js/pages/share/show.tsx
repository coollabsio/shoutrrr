import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { PostPreview, StatusChip } from '@/components/post-preview';
import { dayjs } from '@/lib/datetime/dayjs';
import type { PublicPostView } from '@/pages/share/types';

/* -------------------------------------------------------------------------- */
/* Atmosphere                                                                  */
/* -------------------------------------------------------------------------- */

/**
 * Fixed background for the public share page: a primary-tinted gradient mesh
 * over a faint dotted grid. Pure decoration — sits behind everything and
 * ignores pointer events. Reads well in light and dark.
 */
function ShareScene() {
    return (
        <div
            aria-hidden
            className="pointer-events-none fixed inset-0 -z-10 overflow-hidden"
        >
            <div className="absolute inset-0 bg-background" />
            <div
                className="absolute inset-0 opacity-[0.35]"
                style={{
                    background:
                        'radial-gradient(60rem 60rem at 12% -10%, color-mix(in oklch, var(--primary) 28%, transparent), transparent 60%),' +
                        'radial-gradient(50rem 50rem at 110% 10%, color-mix(in oklch, var(--primary) 14%, transparent), transparent 55%),' +
                        'radial-gradient(70rem 50rem at 50% 120%, color-mix(in oklch, var(--primary) 16%, transparent), transparent 60%)',
                }}
            />
            <div
                className="absolute inset-0 [mask-image:radial-gradient(80%_60%_at_50%_0%,black,transparent)] opacity-[0.5]"
                style={{
                    backgroundImage:
                        'radial-gradient(currentColor 0.5px, transparent 0.5px)',
                    backgroundSize: '22px 22px',
                    color: 'color-mix(in oklch, var(--foreground) 8%, transparent)',
                }}
            />
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Chrome                                                                      */
/* -------------------------------------------------------------------------- */

function ShareHeader() {
    return (
        <header className="sticky top-0 z-20 border-b border-border/70 bg-background/70 backdrop-blur-xl">
            <div className="mx-auto flex max-w-3xl items-center justify-between gap-3 px-5 py-3 sm:px-8">
                <div className="flex items-center gap-2.5">
                    <span className="font-[family-name:var(--font-display)] text-[18px] font-semibold tracking-tight text-foreground">
                        Shoutrrr
                    </span>
                </div>
                <span className="inline-flex items-center gap-1.5 rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] font-medium tracking-wide text-muted-foreground">
                    <svg
                        width="12"
                        height="12"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden
                    >
                        <path
                            d="M12 5c-5 0-8.5 4.5-9.5 7 1 2.5 4.5 7 9.5 7s8.5-4.5 9.5-7c-1-2.5-4.5-7-9.5-7Z"
                            stroke="currentColor"
                            strokeWidth="1.6"
                        />
                        <circle
                            cx="12"
                            cy="12"
                            r="2.6"
                            stroke="currentColor"
                            strokeWidth="1.6"
                        />
                    </svg>
                    Read-only preview
                </span>
            </div>
        </header>
    );
}

function ShareFooter() {
    return (
        <footer className="mx-auto max-w-3xl px-5 pt-10 pb-16 text-center sm:px-8">
            <div className="mx-auto mb-5 h-px w-16 bg-border" />
            <p className="text-[12px] text-muted-foreground">
                Shared with{' '}
                <span className="font-medium text-foreground">Shoutrrr</span> —
                self-hostable social scheduling.
            </p>
        </footer>
    );
}

function ShareShell({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen">
            <ShareScene />
            <ShareHeader />
            {children}
            <ShareFooter />
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Not available                                                               */
/* -------------------------------------------------------------------------- */

function ShareNotAvailable() {
    return (
        <main className="mx-auto grid max-w-3xl place-items-center px-5 py-24 sm:px-8">
            <div className="max-w-sm animate-in text-center duration-500 fill-mode-both zoom-in-95 fade-in">
                <span className="mx-auto grid size-14 place-items-center rounded-2xl border border-border bg-card/70 text-muted-foreground shadow-sm">
                    <svg
                        width="26"
                        height="26"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden
                    >
                        <path
                            d="M9 12a3 3 0 0 1 3-3h2.5M15 12a3 3 0 0 1-3 3H9.5"
                            stroke="currentColor"
                            strokeWidth="1.6"
                            strokeLinecap="round"
                        />
                        <path
                            d="m5 5 14 14"
                            stroke="currentColor"
                            strokeWidth="1.6"
                            strokeLinecap="round"
                        />
                    </svg>
                </span>
                <h1 className="mt-5 font-[family-name:var(--font-display)] text-[22px] font-semibold tracking-tight text-foreground">
                    This link isn&apos;t available
                </h1>
                <p className="mt-2 text-[13.5px] leading-relaxed text-muted-foreground">
                    The share link is no longer available — it may have expired
                    or been revoked by its owner.
                </p>
            </div>
        </main>
    );
}

/* -------------------------------------------------------------------------- */
/* Hero                                                                        */
/* -------------------------------------------------------------------------- */

function headline(text: string): string {
    const line = (
        text.split('\n').find((l) => l.trim().length > 0) ?? ''
    ).trim();
    if (!line) {
        return 'Untitled post';
    }
    return line.length > 120 ? `${line.slice(0, 120)}…` : line;
}

function Dot() {
    return (
        <span
            aria-hidden
            className="size-[3px] rounded-full bg-muted-foreground/50"
        />
    );
}

function ShareHero({ post }: { post: PublicPostView }) {
    const accounts = post.targets.length;
    const platforms = new Set(post.targets.map((t) => t.platform)).size;
    const created = dayjs(post.created_at);
    const isScheduled =
        post.status === 'scheduled' && post.scheduled_at != null;
    return (
        <div className="animate-in duration-700 fill-mode-both fade-in slide-in-from-bottom-3">
            <div className="flex flex-wrap items-center gap-2.5">
                <p className="text-[11px] font-semibold tracking-[0.18em] text-primary uppercase">
                    Shared post
                </p>
                <StatusChip status={post.status} />
            </div>
            <h1 className="mt-3 font-[family-name:var(--font-display)] text-[28px] leading-[1.15] font-semibold tracking-tight text-balance text-foreground sm:text-[34px]">
                {headline(post.base_text)}
            </h1>
            <div className="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px] text-muted-foreground">
                {isScheduled ? (
                    <span>
                        Scheduled for{' '}
                        {dayjs(post.scheduled_at as string).format(
                            'MMM D, YYYY · h:mm A',
                        )}
                    </span>
                ) : (
                    <span>{created.format('MMMM D, YYYY')}</span>
                )}
                <Dot />
                <span>
                    {platforms} {platforms === 1 ? 'platform' : 'platforms'}
                </span>
                <Dot />
                <span>
                    {accounts} {accounts === 1 ? 'account' : 'accounts'}
                </span>
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Page                                                                        */
/* -------------------------------------------------------------------------- */

type Props = {
    post: PublicPostView | null;
};

export default function ShareShow({ post }: Props) {
    return (
        <>
            <Head>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <ShareShell>
                {post === null ? (
                    <ShareNotAvailable />
                ) : (
                    <main className="mx-auto max-w-3xl px-5 pt-12 pb-4 sm:px-8 sm:pt-16">
                        <ShareHero post={post} />
                        <PostPreview post={post} className="mt-8" />
                    </main>
                )}
            </ShareShell>
        </>
    );
}

/*
 * This page renders bare (no app sidebar/shell). The opt-out lives in the
 * global layout resolver in `app.tsx` — pages under `share/` return `null`.
 * A per-page `.layout` property is NOT honored by that
 * name-based resolver, so the override must stay in app.tsx.
 */
