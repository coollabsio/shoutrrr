import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, BookText, Bot, Home } from 'lucide-react';

import AppLogoIcon from '@/components/layout/app-logo-icon';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

const DOCS_URL = 'https://shoutrrr.com/docs/mcp';

export default function McpLanding() {
    return (
        <>
            <Head title="MCP endpoint" />
            <main className="relative isolate flex min-h-screen items-center justify-center overflow-hidden bg-background px-6 py-16 text-foreground sm:px-8">
                <div className="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,color-mix(in_oklch,var(--primary)_28%,transparent),transparent_34rem),radial-gradient(circle_at_bottom_right,color-mix(in_oklch,var(--primary)_18%,transparent),transparent_28rem)]" />
                <div className="w-full max-w-xl rounded-[2rem] border bg-card/90 p-8 text-center shadow-2xl shadow-primary/5 backdrop-blur sm:p-10">
                    <div className="mx-auto mb-8 flex size-14 items-center justify-center rounded-2xl border bg-background shadow-sm">
                        <AppLogoIcon className="size-8 text-primary" />
                    </div>

                    <p className="inline-flex items-center gap-1.5 text-sm font-semibold tracking-[0.35em] text-primary uppercase">
                        <Bot className="size-4" />
                        MCP Endpoint
                    </p>
                    <h1 className="mt-4 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                        Hey there! This one's for the robots 🤖
                    </h1>
                    <p className="mx-auto mt-4 max-w-md text-sm leading-6 text-muted-foreground sm:text-base">
                        You've found the Shoutrrr Model Context Protocol (MCP)
                        endpoint. It's how AI agents talk to Shoutrrr, so
                        there's not much to see in a browser — but you're in the
                        right place if you're setting one up.
                    </p>

                    <div className="mx-auto mt-6 max-w-md rounded-xl border bg-muted/50 p-4 text-left">
                        <p className="text-xs leading-5 text-muted-foreground">
                            Under the hood, agents connect by sending
                            authenticated{' '}
                            <code className="rounded bg-background px-1.5 py-0.5 font-mono text-[0.8em] text-foreground">
                                POST
                            </code>{' '}
                            requests here and completing a quick OAuth
                            handshake. The docs walk you through it.
                        </p>
                    </div>

                    <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                        <Button
                            nativeButton={false}
                            size="lg"
                            render={<a href={DOCS_URL} />}
                        >
                            <BookText />
                            Read the MCP docs
                            <ArrowUpRight />
                        </Button>
                        <Button
                            nativeButton={false}
                            variant="outline"
                            size="lg"
                            render={<Link href={home().url} />}
                        >
                            <Home />
                            Back to Shoutrrr
                        </Button>
                    </div>
                </div>
            </main>
        </>
    );
}
