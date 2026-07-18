import { useHttp, usePage } from '@inertiajs/react';
import { toBlob } from 'html-to-image';
import { MessageSquarePlus } from 'lucide-react';
import { useEffect, useState } from 'react';
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
    buildFeedbackPayload,
    type FeedbackType,
} from './build-feedback-payload';

const TYPES: { value: FeedbackType; label: string }[] = [
    { value: 'bug', label: '🐞 Bug' },
    { value: 'feedback', label: '💡 Feedback' },
    { value: 'question', label: '❓ Question' },
];

/**
 * Floating feedback trigger, mounted globally in the app layout. Captures a
 * screenshot of the page (everything but itself) the moment it opens, so the
 * report always carries visual context unless the user opts out.
 */
export default function FeedbackWidget() {
    const enabled = usePage().props.features?.feedback;

    const [open, setOpen] = useState(false);
    const [type, setType] = useState<FeedbackType>('bug');
    const [message, setMessage] = useState('');
    const [screenshot, setScreenshot] = useState<Blob | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [includeShot, setIncludeShot] = useState(true);
    const [capturing, setCapturing] = useState(false);
    const [sending, setSending] = useState(false);

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
        setCapturing(true);
        try {
            const blob = await toBlob(document.body, {
                filter: (node) =>
                    !(
                        node instanceof HTMLElement &&
                        node.dataset.feedbackIgnore !== undefined
                    ),
                cacheBust: true,
                skipFonts: true,
            });
            setScreenshot(blob);
            setPreviewUrl(blob ? URL.createObjectURL(blob) : null);
        } catch {
            setScreenshot(null);
            setPreviewUrl(null);
        } finally {
            setCapturing(false);
        }
    }

    function onOpenChange(next: boolean) {
        setOpen(next);
        if (next) {
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

                <Button onClick={submit} disabled={!canSend} className="w-full">
                    {sending ? 'Sending…' : 'Send feedback'}
                </Button>
            </PopoverContent>
        </Popover>
    );
}
