import { router } from '@inertiajs/react';
import { domAnimation, LazyMotion, m, MotionConfig } from 'motion/react';
import { useState } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import AppLogoIcon from '@/components/layout/app-logo-icon';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { welcomed as welcomedRoute } from '@/routes/onboarding';

const DESTINATIONS = ['x', 'bluesky', 'linkedin'] as const;

// One-time entrance: the disc springs in, then the destinations stagger on after
// it. No looping animation — the hero settles and stays put.
const stack = {
    hidden: {},
    show: { transition: { staggerChildren: 0.09, delayChildren: 0.32 } },
};
const chip = {
    hidden: { opacity: 0, y: 8, scale: 0.7 },
    show: { opacity: 1, y: 0, scale: 1 },
};

export function WelcomeModal({ welcomed }: { welcomed: boolean }) {
    const [open, setOpen] = useState(!welcomed);

    function finish() {
        setOpen(false);
        router.post(
            welcomedRoute().url,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? setOpen(true) : finish())}
        >
            <DialogContent className="sm:max-w-md">
                <LazyMotion features={domAnimation}>
                    <MotionConfig reducedMotion="user">
                        {/* Hero: a compose mark in a soft brand glow, with the
                            destinations stacked beneath it. Decorative — the copy
                            carries the meaning. */}
                        <div
                            aria-hidden
                            className="mx-auto flex flex-col items-center gap-3.5 pt-2"
                        >
                            <div className="relative grid size-16 place-items-center">
                                <m.span
                                    className="pointer-events-none absolute size-28 rounded-full bg-primary/15 blur-2xl"
                                    initial={{ opacity: 0, scale: 0.5 }}
                                    animate={{ opacity: 1, scale: 1 }}
                                    transition={{
                                        duration: 0.7,
                                        ease: 'easeOut',
                                    }}
                                />
                                <m.span
                                    className="relative grid size-14 place-items-center rounded-full bg-primary text-primary-foreground shadow-md"
                                    initial={{ opacity: 0, scale: 0.5 }}
                                    animate={{ opacity: 1, scale: 1 }}
                                    transition={{
                                        type: 'spring',
                                        stiffness: 320,
                                        damping: 20,
                                        delay: 0.08,
                                    }}
                                >
                                    <AppLogoIcon className="size-7" />
                                </m.span>
                            </div>

                            <m.div
                                className="flex items-center"
                                variants={stack}
                                initial="hidden"
                                animate="show"
                            >
                                {DESTINATIONS.map((platform, i) => (
                                    <m.span
                                        key={platform}
                                        variants={chip}
                                        className={cn(
                                            'flex size-7 items-center justify-center rounded-full border bg-card text-muted-foreground ring-2 ring-background',
                                            i > 0 && '-ml-2',
                                        )}
                                    >
                                        <PlatformGlyph
                                            platform={platform}
                                            size={13}
                                        />
                                    </m.span>
                                ))}
                            </m.div>
                        </div>
                    </MotionConfig>
                </LazyMotion>

                <DialogHeader>
                    <DialogTitle>
                        Welcome to{' '}
                        <span className="bg-gradient-to-br from-[color-mix(in_oklch,var(--primary)_70%,black)] to-[color-mix(in_oklch,var(--primary)_48%,black)] bg-clip-text text-transparent dark:from-primary dark:to-[color-mix(in_oklch,var(--primary)_65%,white)]">
                            Shoutrrr
                        </span>
                    </DialogTitle>
                    <DialogDescription>
                        Write once, send everywhere. Connect a destination and
                        your posts go out to every account at once.
                    </DialogDescription>
                </DialogHeader>

                {/* sm:flex-col is required: it cancels DialogFooter's default
                    sm:flex-row, which would otherwise put the two w-full buttons
                    in a row and overflow the modal. */}
                <DialogFooter className="flex-col gap-2 sm:flex-col">
                    <Button
                        className="w-full"
                        onClick={() => {
                            setOpen(false);
                            router.post(
                                welcomedRoute().url,
                                { connect: true },
                                { preserveScroll: true },
                            );
                        }}
                    >
                        Connect an account
                    </Button>
                    <Button variant="ghost" className="w-full" onClick={finish}>
                        Take a look around
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
