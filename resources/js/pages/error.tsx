import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Home } from 'lucide-react';

import AppLogoIcon from '@/components/layout/app-logo-icon';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

type Props = {
    status: 403 | 404 | 405 | 419 | 500 | 503;
};

const errorCopy: Record<
    Props['status'],
    { title: string; description: string }
> = {
    403: {
        title: 'Access denied',
        description:
            "You don't have permission to view this page. If this looks wrong, ask a workspace admin to check your access.",
    },
    404: {
        title: 'Page not found',
        description:
            'The page may have moved, been deleted, or the link may be misspelled.',
    },
    405: {
        title: 'Action not available',
        description:
            "This address exists, but it can't be opened with this kind of request. Try returning to the previous page.",
    },
    419: {
        title: 'Session expired',
        description:
            'Your session timed out before the request finished. Go back and try again.',
    },
    500: {
        title: 'Something went wrong',
        description:
            'We hit an unexpected problem. Please try again in a moment.',
    },
    503: {
        title: 'Temporarily unavailable',
        description:
            'Shoutrrr is briefly unavailable while we perform maintenance or recover capacity.',
    },
};

export default function ErrorPage({ status }: Props) {
    const copy = errorCopy[status] ?? errorCopy[500];

    return (
        <>
            <Head title={`${status} — ${copy.title}`} />
            <main className="relative isolate flex min-h-screen items-center justify-center overflow-hidden bg-background px-6 py-16 text-foreground sm:px-8">
                <div className="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,color-mix(in_oklch,var(--primary)_28%,transparent),transparent_34rem),radial-gradient(circle_at_bottom_right,color-mix(in_oklch,var(--primary)_18%,transparent),transparent_28rem)]" />
                <div className="w-full max-w-xl rounded-[2rem] border bg-card/90 p-8 text-center shadow-2xl shadow-primary/5 backdrop-blur sm:p-10">
                    <div className="mx-auto mb-8 flex size-14 items-center justify-center rounded-2xl border bg-background shadow-sm">
                        <AppLogoIcon className="size-8 text-primary" />
                    </div>

                    <p className="text-sm font-semibold tracking-[0.35em] text-primary uppercase">
                        Error {status}
                    </p>
                    <h1 className="mt-4 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                        {copy.title}
                    </h1>
                    <p className="mx-auto mt-4 max-w-md text-sm leading-6 text-muted-foreground sm:text-base">
                        {copy.description}
                    </p>

                    <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                        <Button
                            size="lg"
                            nativeButton={false}
                            render={<Link href={home().url} />}
                        >
                            <Home />
                            Go home
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="lg"
                            onClick={() => window.history.back()}
                        >
                            <ArrowLeft />
                            Go back
                        </Button>
                    </div>
                </div>
            </main>
        </>
    );
}
