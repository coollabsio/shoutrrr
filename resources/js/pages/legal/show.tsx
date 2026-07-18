import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { dayjs } from '@/lib/datetime/dayjs';
import type { PublicLegalPageView } from '@/types/legal';

/* -------------------------------------------------------------------------- */
/* Atmosphere                                                                  */
/* -------------------------------------------------------------------------- */

/**
 * Fixed background for the public legal page: a primary-tinted gradient mesh
 * over a faint dotted grid. Pure decoration — sits behind everything and
 * ignores pointer events. Reads well in light and dark. Mirrors the public
 * share page so the two neutral surfaces feel like one family.
 */
function LegalScene() {
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

function LegalHeader() {
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
                            d="M12 3 5 6v5c0 4.3 2.9 8 7 9 4.1-1 7-4.7 7-9V6l-7-3Z"
                            stroke="currentColor"
                            strokeWidth="1.6"
                            strokeLinejoin="round"
                        />
                    </svg>
                    Legal
                </span>
            </div>
        </header>
    );
}

function LegalFooter() {
    return (
        <footer className="mx-auto max-w-3xl px-5 pt-10 pb-16 text-center sm:px-8">
            <div className="mx-auto mb-5 h-px w-16 bg-border" />
            <p className="text-[12px] text-muted-foreground">
                Published with{' '}
                <span className="font-medium text-foreground">Shoutrrr</span> —
                self-hostable social scheduling.
            </p>
        </footer>
    );
}

function LegalShell({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen">
            <LegalScene />
            <LegalHeader />
            {children}
            <LegalFooter />
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Page                                                                        */
/* -------------------------------------------------------------------------- */

type Props = {
    page: PublicLegalPageView;
};

export default function LegalShow({ page }: Props) {
    const updated = dayjs(page.updated_at);

    return (
        <>
            <Head title={page.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <LegalShell>
                <main className="mx-auto max-w-3xl px-5 pt-12 pb-4 sm:px-8 sm:pt-16">
                    <div className="animate-in duration-700 fill-mode-both fade-in slide-in-from-bottom-3">
                        <p className="text-[11px] font-semibold tracking-[0.18em] text-primary uppercase">
                            Legal
                        </p>
                        <h1 className="mt-3 font-[family-name:var(--font-display)] text-[28px] leading-[1.15] font-semibold tracking-tight text-balance text-foreground sm:text-[34px]">
                            {page.title}
                        </h1>
                        <p className="mt-4 text-[12.5px] text-muted-foreground">
                            Last updated{' '}
                            <time dateTime={page.updated_at}>
                                {updated.format('MMMM D, YYYY')}
                            </time>
                        </p>
                    </div>

                    {/*
                     * `content_html` is sanitized on the server on write (see
                     * App\Services\Legal\LegalHtmlSanitizer) and served under a
                     * strict CSP, so it is safe to inject here.
                     */}
                    <article
                        className="legal-prose mt-8 animate-in duration-700 fill-mode-both fade-in"
                        dangerouslySetInnerHTML={{ __html: page.content_html }}
                    />
                </main>
            </LegalShell>
        </>
    );
}

/*
 * This page renders bare (no app sidebar/shell). The opt-out lives in the
 * global layout resolver in `app.tsx` — pages under `legal/` return `null`.
 * A per-page `.layout` property is NOT honored by that name-based resolver, so
 * the override must stay in app.tsx.
 */
