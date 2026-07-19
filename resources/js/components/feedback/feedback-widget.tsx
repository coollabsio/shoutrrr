import { useHttp, usePage } from '@inertiajs/react';
import { toBlob } from 'html-to-image';
import { MessageSquarePlus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import FeedbackController from '@/actions/App/Http/Controllers/FeedbackController';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    type DiagnosticsSnapshot,
    snapshotDiagnostics,
} from '@/lib/diagnostics-collector';
import { redactInertiaProps } from '@/lib/redact-inertia-props';

import {
    buildFeedbackPayload,
    type FeedbackType,
} from './build-feedback-payload';

const TYPES: { value: FeedbackType; label: string }[] = [
    { value: 'bug', label: '🐞 Bug' },
    { value: 'feedback', label: '💡 Feedback' },
    { value: 'question', label: '❓ Question' },
];

/**
 * Resolve once the browser is idle (or after 200ms, whichever comes first), so
 * heavy work scheduled after it runs only once the popover has opened and
 * painted — never on the click that opens it. Mirrors the emoji picker's
 * warm-up scheduling. Falls back to a two-frame wait where rIC is unavailable.
 */
function whenIdle(): Promise<void> {
    return new Promise((resolve) => {
        const idle = window as Window & {
            requestIdleCallback?: (
                callback: () => void,
                options?: { timeout: number },
            ) => number;
        };
        if (typeof idle.requestIdleCallback === 'function') {
            idle.requestIdleCallback(() => resolve(), { timeout: 200 });

            return;
        }
        requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
    });
}

/**
 * Floating feedback trigger, mounted globally in the app layout. Captures a
 * screenshot of the page (everything but itself) the moment it opens, so the
 * report always carries visual context unless the user opts out.
 */
/**
 * A diagnostics snapshot plus the page's Inertia props (redacted + bounded) and
 * component name, so a report shows the exact data the page rendered with.
 */
type FeedbackDiagnostics = DiagnosticsSnapshot & {
    pageComponent: string;
    pageProps: unknown;
};

export default function FeedbackWidget() {
    const page = usePage();
    const enabled = page.props.features?.feedback;

    const [open, setOpen] = useState(false);
    const [type, setType] = useState<FeedbackType>('bug');
    const [message, setMessage] = useState('');
    const [screenshot, setScreenshot] = useState<Blob | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [includeShot, setIncludeShot] = useState(true);
    const [diagnostics, setDiagnostics] = useState<FeedbackDiagnostics | null>(
        null,
    );
    const [includeDiagnostics, setIncludeDiagnostics] = useState(true);
    const [capturing, setCapturing] = useState(false);
    const [sending, setSending] = useState(false);

    // Monotonic id for the in-flight capture. Because capture() is async
    // (idle-wait + toBlob), a reopen can start a new capture while an older one
    // is still running; only the latest may write its result, so a slow earlier
    // capture never overwrites a fresh one with a stale screenshot.
    const captureIdRef = useRef(0);

    const http = useHttp<Record<string, never>, { ok: boolean }>({});

    // Object URLs are scoped to whatever `previewUrl` currently points at; this
    // revokes the previous one whenever it's replaced (reopen, reset) and
    // whatever is left when the widget unmounts, without relying on a stale
    // closure over `previewUrl` inside an event handler.
    useEffect(() => {
        return () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        };
    }, [previewUrl]);

    if (!enabled) {
        return null;
    }

    async function capture() {
        captureIdRef.current += 1;
        const id = captureIdRef.current;

        // Drop any prior capture immediately so a reopen shows the skeleton, not
        // the previous page's stale screenshot, and nothing stale can be sent
        // while the fresh one is in flight.
        setCapturing(true);
        setScreenshot(null);
        setPreviewUrl(null);

        try {
            // html-to-image clones the whole page and reads computed styles for
            // every node — hundreds of ms of *synchronous* main-thread work. Wait
            // for the thread to be idle so the popover opens and paints its
            // skeleton first; the capture then fills in without janking the open.
            await whenIdle();

            const blob = await toBlob(document.body, {
                filter: (node) =>
                    !(
                        node instanceof HTMLElement &&
                        // Skip the widget itself and any kept-warm popover (e.g.
                        // the force-mounted emoji picker's virtualized grid),
                        // which would otherwise bloat the clone enormously.
                        (node.dataset.feedbackIgnore !== undefined ||
                            node.dataset.keepWarm !== undefined)
                    ),
                // Cap the raster at 1x — a full-page screenshot at a 2x DPR is 4x
                // the pixels to encode and decode for a thumbnail nobody zooms.
                pixelRatio: 1,
                skipFonts: true,
                // Deliberately NOT cacheBust: it appends a unique query to every
                // image URL, forcing a full re-download of the page's images on
                // each open. Cached images are fine for a screenshot.
            });

            // A newer open superseded this capture — discard the stale result
            // and leave `capturing` for the newer capture to clear.
            if (captureIdRef.current !== id) {
                return;
            }
            setScreenshot(blob);
            setPreviewUrl(blob ? URL.createObjectURL(blob) : null);
            setCapturing(false);
        } catch {
            if (captureIdRef.current !== id) {
                return;
            }
            setScreenshot(null);
            setPreviewUrl(null);
            setCapturing(false);
        }
        // No finally: the React Compiler bails on try/finally, so clear
        // `capturing` in each terminal path instead.
    }

    function onOpenChange(next: boolean) {
        setOpen(next);
        if (next) {
            // Re-snapshot on every open — the user may have navigated or acted
            // since last time, so the breadcrumbs, page props, and screenshot
            // must all be fresh. Props are redacted + bounded before attaching.
            setDiagnostics({
                ...snapshotDiagnostics(),
                pageComponent: page.component,
                pageProps: redactInertiaProps(page.props),
            });
            void capture();
        }
    }

    function reset() {
        setMessage('');
        setType('bug');
        setScreenshot(null);
        // The revoke-on-change effect (keyed on previewUrl) cleans up the
        // outgoing object URL; this just clears the preview itself.
        setPreviewUrl(null);
        setIncludeShot(true);
        setDiagnostics(null);
        setIncludeDiagnostics(true);
    }

    function submit() {
        setSending(true);
        http.transform(() =>
            buildFeedbackPayload({
                type,
                message: message.trim(),
                url: window.location.href,
                browser: navigator.userAgent,
                screenshot: includeShot ? screenshot : null,
                diagnostics:
                    includeDiagnostics && diagnostics
                        ? JSON.stringify(diagnostics)
                        : null,
            }),
        );
        http.post(FeedbackController.url(), {
            onSuccess: () => {
                toast.success("Thanks — we've got it.");
                setOpen(false);
                reset();
            },
            onError: (errors) => {
                if (errors?.screenshot) {
                    toast.error(
                        'Screenshot is too large to send. Turn it off and try again.',
                    );
                } else if (errors?.diagnostics) {
                    toast.error(
                        'Diagnostics are too large to send. Turn them off and try again.',
                    );
                } else {
                    const first = errors ? Object.values(errors)[0] : undefined;
                    toast.error(
                        typeof first === 'string'
                            ? first
                            : 'Please check your input and try again.',
                    );
                }
            },
            onHttpException: () => {
                toast.error('Could not send feedback. Try again in a moment.');
            },
            onNetworkError: () => {
                toast.error('No connection. Try again in a moment.');
            },
            onFinish: () => setSending(false),
        }).catch(() => {});
    }

    const canSend = message.trim() !== '' && !capturing && !sending;

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger
                render={
                    <Button
                        data-feedback-ignore
                        className="fixed right-5 bottom-5 z-40 size-11 rounded-full shadow-lg transition-[transform,box-shadow] hover:-translate-y-0.5 hover:shadow-xl"
                        aria-label="Send feedback"
                    />
                }
            >
                <MessageSquarePlus className="size-5" />
            </PopoverTrigger>

            <PopoverContent
                data-feedback-ignore
                align="end"
                side="top"
                sideOffset={12}
                className="w-80"
            >
                <PopoverHeader>
                    <PopoverTitle>Send feedback</PopoverTitle>
                    <PopoverDescription>
                        Bugs, ideas, or questions — goes straight to the team.
                    </PopoverDescription>
                </PopoverHeader>

                <ToggleGroup
                    value={[type]}
                    onValueChange={(next) => {
                        const value = next[0];
                        if (value) {
                            setType(value as FeedbackType);
                        }
                    }}
                    variant="outline"
                    size="sm"
                    className="w-full"
                >
                    {TYPES.map((item) => (
                        <ToggleGroupItem
                            key={item.value}
                            value={item.value}
                            className="flex-1 text-xs"
                        >
                            {item.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>

                <Textarea
                    value={message}
                    onChange={(e) => setMessage(e.target.value)}
                    maxLength={2000}
                    placeholder="What's going on, or what would help?"
                    rows={4}
                />

                {previewUrl ? (
                    <div className="flex items-center gap-3">
                        <img
                            src={previewUrl}
                            alt="Screenshot preview"
                            className="h-14 w-20 shrink-0 rounded-lg border border-border object-cover"
                        />
                        <div className="flex flex-1 items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span>Include screenshot</span>
                            <Switch
                                checked={includeShot}
                                onCheckedChange={(checked) =>
                                    setIncludeShot(checked)
                                }
                                aria-label="Include screenshot in report"
                            />
                        </div>
                    </div>
                ) : capturing ? (
                    <div className="flex items-center gap-3">
                        <div className="h-14 w-20 shrink-0 animate-pulse rounded-lg bg-muted" />
                        <p className="text-xs text-muted-foreground">
                            Capturing screenshot…
                        </p>
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        No screenshot captured.
                    </p>
                )}

                {diagnostics ? (
                    <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span>
                            Attach logs ({diagnostics.logs.length} console ·{' '}
                            {diagnostics.network.length} requests)
                        </span>
                        <Switch
                            checked={includeDiagnostics}
                            onCheckedChange={(checked) =>
                                setIncludeDiagnostics(checked)
                            }
                            aria-label="Attach console and network logs to report"
                        />
                    </div>
                ) : null}

                <Button onClick={submit} disabled={!canSend} className="w-full">
                    {sending ? 'Sending…' : 'Send feedback'}
                </Button>
            </PopoverContent>
        </Popover>
    );
}
